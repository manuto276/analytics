<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\TransactionIsolationLevel;
use Psr\Clock\ClockInterface;

/**
 * Write path: visit resolution, events_raw insert, visit aggregates, consented visitors and touches,
 * consent counters and dirty-day marks, all in one transaction per site batch.
 */
final class IngestBatchHandler
{
    public const int VISIT_TIMEOUT_SECONDS = 1800;
    private const int MAX_ATTEMPTS = 4;

    /** @var array<string, array{visit_id: int, visit_day: string, last: \DateTimeImmutable, source_key: ?string, level: string, dirty?: true}> */
    private array $lookups = [];
    /** @var array<int, array{day: string, pageviews: int, events: int, engagement: int, last: \DateTimeImmutable, exit: ?string, level: ?string, visitor_id: ?string}> */
    private array $visitUpdates = [];
    /** @var list<array{draft: EventDraft, visit_id: ?int, is_entry: bool}> */
    private array $rows = [];
    /** @var array<string, true> */
    private array $dirty = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SiteRepository $sites,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param list<EventDraft> $drafts
     *
     * @return int number of stored events
     */
    public function handle(array $drafts): int
    {
        $bySite = [];
        foreach ($drafts as $draft) {
            $bySite[$draft->siteId][] = $draft;
        }
        $stored = 0;
        foreach ($bySite as $siteId => $siteDrafts) {
            $site = $this->sites->snapshot($siteId);
            if ($site === null) {
                continue;
            }
            usort($siteDrafts, static fn(EventDraft $a, EventDraft $b): int => $a->occurredAt <=> $b->occurredAt);
            $stored += $this->handleSite($site, $siteDrafts);
        }

        return $stored;
    }

    /** @param list<EventDraft> $drafts */
    private function handleSite(SiteSnapshot $site, array $drafts): int
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->reset();
            $nested = $this->connection->isTransactionActive();
            if (!$nested) {
                $this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            }
            $this->connection->beginTransaction();
            try {
                $count = $this->process($site, $drafts);
                $this->connection->commit();

                return $count;
            } catch (RetryableException|UniqueConstraintViolationException $e) {
                $this->connection->rollBack();
                if ($nested || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep(random_int(5_000, 50_000) * $attempt);
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                throw $e;
            }
        }
    }

    private function reset(): void
    {
        $this->lookups = [];
        $this->visitUpdates = [];
        $this->rows = [];
        $this->dirty = [];
    }

    /** @param list<EventDraft> $drafts */
    private function process(SiteSnapshot $site, array $drafts): int
    {
        $drafts = $this->withoutDuplicates($site->id, $drafts);
        $consentCounters = [];

        foreach ($drafts as $draft) {
            if ($draft->type === EventType::ConsentStat) {
                if ($draft->consentStat !== null) {
                    $key = $draft->localDay . '|' . $draft->consentVersion;
                    $consentCounters[$key][$draft->consentStat->column()] = ($consentCounters[$key][$draft->consentStat->column()] ?? 0) + 1;
                }
                continue;
            }

            $visitId = null;
            $isEntry = false;
            $key = $draft->visitKey();
            if ($key !== null) {
                [$visitId, $isEntry] = $this->resolveVisit($site, $draft, $key);
            } elseif ($draft->type === EventType::Pageview) {
                // pageviews_only: an entry is a pageview not coming from the site itself.
                $isEntry = $draft->channel->value !== 'internal';
            }

            if ($visitId !== null) {
                $this->accumulate($visitId, $draft);
            }
            $this->rows[] = ['draft' => $draft, 'visit_id' => $visitId, 'is_entry' => $isEntry];
            $this->dirty[$draft->localDay] = true;
        }

        $this->insertEvents($site->id);
        $this->flushVisits($site->id);
        $this->flushLookups($site->id);
        foreach ($consentCounters as $key => $columns) {
            [$day, $version] = explode('|', $key);
            $this->incrementConsentStats($site->id, $day, (int) $version, $columns);
        }
        $this->markDirty($site->id);

        return \count($this->rows);
    }

    /**
     * @param list<EventDraft> $drafts
     *
     * @return list<EventDraft>
     */
    private function withoutDuplicates(int $siteId, array $drafts): array
    {
        $uids = [];
        $days = [];
        foreach ($drafts as $draft) {
            if ($draft->type !== EventType::ConsentStat) {
                $uids[] = $draft->uid;
                $days[$draft->localDay] = true;
            }
        }
        if ($uids === []) {
            return $drafts;
        }
        $existing = $this->connection->fetchFirstColumn(
            'SELECT event_uid FROM events_raw WHERE site_id = ? AND local_day IN (?) AND event_uid IN (?)',
            [$siteId, array_keys($days), $uids],
            [ParameterType::INTEGER, ArrayParameterType::STRING, ArrayParameterType::BINARY],
        );
        if ($existing === []) {
            return $drafts;
        }
        $known = array_fill_keys(array_map(Types::string(...), $existing), true);

        return array_values(array_filter($drafts, static fn(EventDraft $d): bool => $d->type === EventType::ConsentStat || !isset($known[$d->uid])));
    }

    /** @return array{0: ?int, 1: bool} visit id and whether this event starts it */
    private function resolveVisit(SiteSnapshot $site, EventDraft $draft, string $key): array
    {
        $lookup = $this->lookup($site->id, $key);
        $active = $lookup !== null
            && $lookup['visit_day'] === $draft->localDay
            && $draft->occurredAt->getTimestamp() - $lookup['last']->getTimestamp() <= self::VISIT_TIMEOUT_SECONDS;

        if ($active && $draft->type === EventType::Pageview && $site->newVisitOnCampaignChange && $draft->sourceKey !== null && $lookup['source_key'] !== $draft->sourceKey) {
            $active = false;
        }

        if ($draft->type === EventType::ConsentUpgrade && !$active && $draft->hashKey !== null && $draft->hashKey !== $key) {
            // Upgrade the base visit of this page load when it is still active.
            $base = $this->lookup($site->id, $draft->hashKey);
            if ($base !== null && $base['visit_day'] === $draft->localDay && $draft->occurredAt->getTimestamp() - $base['last']->getTimestamp() <= self::VISIT_TIMEOUT_SECONDS) {
                $this->lookups[$key] = ['visit_id' => $base['visit_id'], 'visit_day' => $base['visit_day'], 'last' => $draft->occurredAt, 'source_key' => $base['source_key'], 'level' => 'c', 'dirty' => true];
                $this->upgradeVisit($site, $base['visit_id'], $base['visit_day'], $draft);

                return [$base['visit_id'], false];
            }
        }

        if ($active) {
            \assert($lookup !== null);
            if ($draft->occurredAt > $lookup['last']) {
                $this->lookups[$key]['last'] = $draft->occurredAt;
            }
            $this->lookups[$key]['dirty'] = true;
            if ($draft->type === EventType::ConsentUpgrade && $lookup['level'] !== 'c') {
                $this->upgradeVisit($site, $lookup['visit_id'], $lookup['visit_day'], $draft);
                $this->lookups[$key]['level'] = 'c';
            }

            return [$lookup['visit_id'], false];
        }

        if ($draft->type === EventType::Engagement) {
            return [null, false];
        }

        $visitId = $this->startVisit($site, $draft);
        $this->lookups[$key] = ['visit_id' => $visitId, 'visit_day' => $draft->localDay, 'last' => $draft->occurredAt, 'source_key' => $draft->sourceKey, 'level' => $draft->level->value, 'dirty' => true];
        if ($draft->level === TrackingLevel::Consented && $draft->visitorId !== null) {
            $this->recordConsentedVisit($site, $draft, $visitId);
        }

        return [$visitId, $draft->type === EventType::Pageview || $draft->type === EventType::ConsentUpgrade];
    }

    /** @return array{visit_id: int, visit_day: string, last: \DateTimeImmutable, source_key: ?string, level: string, dirty?: true}|null */
    private function lookup(int $siteId, string $key): ?array
    {
        if (\array_key_exists($key, $this->lookups)) {
            return $this->lookups[$key];
        }
        $row = $this->connection->fetchAssociative(
            'SELECT l.visit_id, l.visit_day, l.last_activity_at, l.source_key, v.level
               FROM visit_lookup l LEFT JOIN visits v ON v.id = l.visit_id AND v.local_day = l.visit_day
              WHERE l.site_id = ? AND l.visitor_key = ? FOR UPDATE',
            [$siteId, $key],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
        if ($row === false) {
            return null;
        }

        return $this->lookups[$key] = [
            'visit_id' => Types::int($row['visit_id']),
            'visit_day' => Types::string($row['visit_day']),
            'last' => new \DateTimeImmutable(Types::string($row['last_activity_at']), new \DateTimeZone('UTC')),
            'source_key' => Types::nullableString($row['source_key']),
            'level' => Types::string($row['level'], 'b'),
        ];
    }

    private function startVisit(SiteSnapshot $site, EventDraft $d): int
    {
        $this->connection->insert('visits', [
            'local_day' => $d->localDay,
            'site_id' => $site->id,
            'level' => $d->level->value,
            'started_at' => self::ts($d->occurredAt),
            'last_activity_at' => self::ts($d->occurredAt),
            'visitor_hash' => $d->visitorHash,
            'visitor_id' => $d->level === TrackingLevel::Consented ? $d->visitorId : null,
            'entry_host' => $d->host,
            'entry_path' => $d->path,
            'entry_page_hash' => $d->pageHash,
            'exit_page_hash' => $d->pageHash,
            'pageviews' => 0,
            'events' => 0,
            'engagement_ms' => 0,
            'is_bounce' => 1,
            'channel' => $d->channel->value,
            'source' => $d->source,
            'referrer_host' => $d->referrerHost,
            'utm_source' => $d->utmSource,
            'utm_medium' => $d->utmMedium,
            'utm_campaign' => $d->utmCampaign,
            'utm_content' => $d->utmContent,
            'utm_term' => $d->utmTerm,
            'browser' => $d->browser,
            'os' => $d->os,
            'device' => $d->device,
            'country' => $d->country,
            'entry_content_key' => $d->contentKey,
        ], ['visitor_id' => ParameterType::BINARY, 'entry_page_hash' => ParameterType::BINARY, 'exit_page_hash' => ParameterType::BINARY]);

        return Types::int($this->connection->lastInsertId());
    }

    private function upgradeVisit(SiteSnapshot $site, int $visitId, string $visitDay, EventDraft $d): void
    {
        $this->connection->executeStatement(
            "UPDATE visits SET level = 'c', visitor_id = COALESCE(visitor_id, ?) WHERE site_id = ? AND id = ? AND local_day = ?",
            [$d->visitorId, $site->id, $visitId, $visitDay],
            [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
        if ($d->visitorId !== null) {
            $this->recordConsentedVisit($site, $d, $visitId, $visitDay);
        }
    }

    /**
     * Upserts the visitor and writes an attribution touch (first visit, then each non-direct entry).
     * $d is the landing event of the visit (pageview or consent upgrade carrying the landing page).
     */
    private function recordConsentedVisit(SiteSnapshot $site, EventDraft $d, int $visitId, ?string $visitDay = null): void
    {
        \assert($d->visitorId !== null);
        $existing = $this->connection->fetchAssociative(
            'SELECT first_seen_at FROM visitors WHERE site_id = ? AND visitor_id = ? FOR UPDATE',
            [$site->id, $d->visitorId],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
        $isNew = $existing === false;
        if ($isNew) {
            $this->connection->insert('visitors', [
                'site_id' => $site->id,
                'visitor_id' => $d->visitorId,
                'first_seen_at' => self::ts($d->occurredAt),
                'last_seen_at' => self::ts($d->occurredAt),
                'visits' => 1,
                'consent_version' => $d->consentVersion,
            ], ['visitor_id' => ParameterType::BINARY]);
        } else {
            $this->connection->executeStatement(
                'UPDATE visitors SET last_seen_at = GREATEST(last_seen_at, ?), visits = visits + 1, consent_version = GREATEST(consent_version, ?) WHERE site_id = ? AND visitor_id = ?',
                [self::ts($d->occurredAt), $d->consentVersion, $site->id, $d->visitorId],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BINARY],
            );
        }

        if ($isNew || $d->channel->isExternal()) {
            $this->connection->insert('attribution_touches', [
                'site_id' => $site->id,
                'visitor_id' => $d->visitorId,
                'visit_id' => $visitId,
                'visit_day' => $visitDay ?? $d->localDay,
                'touched_at' => self::ts($d->occurredAt),
                'is_first' => $isNew ? 1 : 0,
                'channel' => $d->channel->value,
                'source' => $d->source,
                'referrer_host' => $d->referrerHost,
                'utm_source' => $d->utmSource,
                'utm_medium' => $d->utmMedium,
                'utm_campaign' => $d->utmCampaign,
                'utm_content' => $d->utmContent,
                'utm_term' => $d->utmTerm,
                'landing_host' => $d->host,
                'landing_path' => $d->path,
            ], ['visitor_id' => ParameterType::BINARY]);
            if ($isNew) {
                $this->connection->executeStatement(
                    'UPDATE visitors SET first_touch_id = ? WHERE site_id = ? AND visitor_id = ?',
                    [Types::int($this->connection->lastInsertId()), $site->id, $d->visitorId],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BINARY],
                );
            }
        }

        if ($d->consentReceipt) {
            $this->connection->insert('consent_receipts', [
                'site_id' => $site->id,
                'visitor_id' => $d->visitorId,
                'consent_version' => $d->consentVersion,
                'decision' => 'accept',
                'decided_at' => self::ts($d->occurredAt),
            ], ['visitor_id' => ParameterType::BINARY]);
        }
    }

    private function accumulate(int $visitId, EventDraft $d): void
    {
        $lookupDay = null;
        foreach ($this->lookups as $lookup) {
            if ($lookup['visit_id'] === $visitId) {
                $lookupDay = $lookup['visit_day'];
                break;
            }
        }
        $update = $this->visitUpdates[$visitId] ?? ['day' => $lookupDay ?? $d->localDay, 'pageviews' => 0, 'events' => 0, 'engagement' => 0, 'last' => $d->occurredAt, 'exit' => null, 'level' => null, 'visitor_id' => null];
        match ($d->type) {
            EventType::Pageview => [$update['pageviews'], $update['exit']] = [$update['pageviews'] + 1, $d->pageHash],
            EventType::Custom => $update['events'] += 1,
            EventType::Engagement => $update['engagement'] += $d->engagedMs ?? 0,
            default => null,
        };
        if ($d->occurredAt > $update['last']) {
            $update['last'] = $d->occurredAt;
        }
        $this->visitUpdates[$visitId] = $update;
    }

    private function insertEvents(int $siteId): void
    {
        if ($this->rows === []) {
            return;
        }
        $columns = ['local_day', 'site_id', 'event_uid', 'occurred_at', 'received_at', 'level', 'type', 'name', 'visit_id', 'is_entry', 'visitor_hash', 'visitor_id', 'host', 'path', 'page_hash', 'query', 'referrer_host', 'channel', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'browser', 'browser_major', 'os', 'os_major', 'device', 'country', 'content_key', 'engagement_ms', 'scroll_pct', 'props'];
        $binary = ['event_uid', 'visitor_id', 'page_hash'];
        foreach (array_chunk($this->rows, 100) as $chunk) {
            $values = [];
            $params = [];
            $types = [];
            foreach ($chunk as $row) {
                $d = $row['draft'];
                $record = [
                    'local_day' => $d->localDay,
                    'site_id' => $siteId,
                    'event_uid' => $d->uid,
                    'occurred_at' => self::ts($d->occurredAt),
                    'received_at' => self::ts($d->receivedAt),
                    'level' => $d->level->value,
                    'type' => $d->type->value,
                    'name' => $d->name,
                    'visit_id' => $row['visit_id'],
                    'is_entry' => $row['is_entry'] ? 1 : 0,
                    'visitor_hash' => $d->visitorHash,
                    'visitor_id' => $d->level === TrackingLevel::Consented ? $d->visitorId : null,
                    'host' => $d->host,
                    'path' => $d->path,
                    'page_hash' => $d->pageHash,
                    'query' => $d->query,
                    'referrer_host' => $d->referrerHost,
                    'channel' => $d->channel->value,
                    'source' => $d->source,
                    'utm_source' => $d->utmSource,
                    'utm_medium' => $d->utmMedium,
                    'utm_campaign' => $d->utmCampaign,
                    'utm_content' => $d->utmContent,
                    'utm_term' => $d->utmTerm,
                    'browser' => $d->browser,
                    'browser_major' => $d->browserMajor,
                    'os' => $d->os,
                    'os_major' => $d->osMajor,
                    'device' => $d->device,
                    'country' => $d->country,
                    'content_key' => $d->contentKey,
                    'engagement_ms' => $d->engagedMs,
                    'scroll_pct' => $d->scrollPct,
                    'props' => $d->props === [] ? null : json_encode($d->props, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                ];
                $values[] = '(' . implode(',', array_fill(0, \count($columns), '?')) . ')';
                foreach ($columns as $column) {
                    $params[] = $record[$column];
                    $types[] = \in_array($column, $binary, true) ? ParameterType::BINARY : (\is_int($record[$column]) ? ParameterType::INTEGER : ParameterType::STRING);
                }
            }
            $this->connection->executeStatement('INSERT IGNORE INTO events_raw (' . implode(',', $columns) . ') VALUES ' . implode(',', $values), $params, $types);
        }
    }

    private function flushVisits(int $siteId): void
    {
        foreach ($this->visitUpdates as $visitId => $u) {
            $this->connection->executeStatement(
                'UPDATE visits SET pageviews = pageviews + ?, events = events + ?, engagement_ms = engagement_ms + ?,
                        last_activity_at = GREATEST(last_activity_at, ?), exit_page_hash = COALESCE(?, exit_page_hash),
                        is_bounce = (pageviews <= 1 AND events = 0)
                  WHERE site_id = ? AND id = ? AND local_day = ?',
                [$u['pageviews'], $u['events'], $u['engagement'], self::ts($u['last']), $u['exit'], $siteId, $visitId, $u['day']],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
            );
        }
    }

    private function flushLookups(int $siteId): void
    {
        foreach ($this->lookups as $key => $lookup) {
            if (!isset($lookup['dirty'])) {
                continue;
            }
            $this->connection->executeStatement(
                'INSERT INTO visit_lookup (site_id, visitor_key, visit_id, visit_day, last_activity_at, source_key) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE visit_id = VALUES(visit_id), visit_day = VALUES(visit_day), last_activity_at = VALUES(last_activity_at), source_key = VALUES(source_key)',
                [$siteId, $key, $lookup['visit_id'], $lookup['visit_day'], self::ts($lookup['last']), $lookup['source_key']],
                [ParameterType::INTEGER, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );
        }
    }

    /** @param array<string, int> $columns */
    private function incrementConsentStats(int $siteId, string $day, int $version, array $columns): void
    {
        $names = array_keys($columns);
        $this->connection->executeStatement(
            'INSERT INTO consent_stats_daily (site_id, day, consent_version, ' . implode(', ', $names) . ') VALUES (?, ?, ?' . str_repeat(', ?', \count($names)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => $c . ' = ' . $c . ' + VALUES(' . $c . ')', $names)),
            [$siteId, $day, $version, ...array_values($columns)],
        );
    }

    private function markDirty(int $siteId): void
    {
        foreach (array_keys($this->dirty) as $day) {
            DirtyDayMarker::mark($this->connection, $siteId, (string) $day, $this->clock->now());
        }
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
