<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tracking\Application\Enrichment\UrlSanitizer;
use Analytics\Tracking\Domain\Channel;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Deterministic demo data written through the real ingestion path (dev:seed, load tests and the
 * golden dataset of the test suite).
 */
final readonly class Seeder
{
    public const array PAGES = ['/', '/pricing', '/blog/analytics-without-cookies', '/blog/gdpr-checklist', '/docs/install', '/contact'];
    public const array CONTENT = [null, null, 'author:1', 'author:2', 'author:1', null];
    public const array SOURCES = [
        ['channel' => Channel::Direct, 'source' => null, 'referrer' => null, 'utm' => []],
        ['channel' => Channel::OrganicSearch, 'source' => 'Google', 'referrer' => 'www.google.com', 'utm' => []],
        ['channel' => Channel::OrganicSearch, 'source' => 'Bing', 'referrer' => 'www.bing.com', 'utm' => []],
        ['channel' => Channel::OrganicSocial, 'source' => 'LinkedIn', 'referrer' => 'www.linkedin.com', 'utm' => []],
        ['channel' => Channel::Referral, 'source' => 'blog.example.org', 'referrer' => 'blog.example.org', 'utm' => []],
        ['channel' => Channel::PaidSearch, 'source' => 'Google', 'referrer' => 'www.google.com', 'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'brand']],
        ['channel' => Channel::Email, 'source' => 'newsletter', 'referrer' => null, 'utm' => ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'september']],
        ['channel' => Channel::PaidSocial, 'source' => 'Facebook', 'referrer' => null, 'utm' => ['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'retarget']],
    ];
    public const array DEVICES = [['desktop', 'Chrome', 126, 'Windows', 10], ['desktop', 'Firefox', 128, 'Mac', 14], ['mobile', 'Safari', 17, 'iOS', 17], ['mobile', 'Chrome', 126, 'Android', 14], ['tablet', 'Safari', 17, 'iOS', 17]];
    public const array COUNTRIES = ['IT', 'DE', 'FR', 'US', 'ES', null];
    public const array EVENT_NAMES = ['signup_click', 'download_pdf', 'contact_form'];

    public function __construct(private Connection $connection, private IngestBatchHandler $handler) {}

    /**
     * @return array{visits: int, events: int, conversions: int}
     */
    public function seed(SiteSnapshot $site, \DateTimeImmutable $lastDay, int $days, int $visitsPerDay, int $seed = 20260917, float $consentedShare = 0.4): array
    {
        mt_srand($seed);
        $stats = ['visits' => 0, 'events' => 0, 'conversions' => 0];
        $host = $site->domains[0]['host'] ?? 'www.example.com';
        $timezone = $site->timezone();

        for ($dayOffset = $days - 1; $dayOffset >= 0; --$dayOffset) {
            $day = $lastDay->modify('-' . $dayOffset . ' days');
            $dayVisits = max(1, (int) round($visitsPerDay * (0.6 + mt_rand(0, 80) / 100)));
            for ($visit = 0; $visit < $dayVisits; ++$visit) {
                $drafts = [];
                $startHour = mt_rand(6, 22);
                $start = new \DateTimeImmutable($day->format('Y-m-d') . \sprintf(' %02d:%02d:%02d', $startHour, mt_rand(0, 59), mt_rand(0, 59)), $timezone);
                $source = self::SOURCES[mt_rand(0, \count(self::SOURCES) - 1)];
                $device = self::DEVICES[mt_rand(0, \count(self::DEVICES) - 1)];
                $country = self::COUNTRIES[mt_rand(0, \count(self::COUNTRIES) - 1)];
                $consented = $site->cookieLevelEnabled && mt_rand(1, 100) <= (int) ($consentedShare * 100);
                $hashKey = random_bytes(16);
                $sessionKey = $consented ? random_bytes(16) : null;
                $visitorId = $consented ? $this->pickVisitor($site, $seed, $visit) : null;
                $pageCount = mt_rand(1, 4);
                $at = $start;

                for ($page = 0; $page < $pageCount; ++$page) {
                    $index = $page === 0 ? mt_rand(0, \count(self::PAGES) - 1) : mt_rand(0, \count(self::PAGES) - 1);
                    $path = self::PAGES[$index];
                    $drafts[] = $this->draft($site, EventType::Pageview, $at, $host, $path, self::CONTENT[$index], $source, $device, $country, $hashKey, $sessionKey, $visitorId, $consented);
                    if ($page > 0 || $pageCount === 1) {
                        $drafts[] = $this->draft($site, EventType::Engagement, $at->modify('+30 seconds'), $host, $path, self::CONTENT[$index], $source, $device, $country, $hashKey, $sessionKey, $visitorId, $consented, engagedMs: mt_rand(3000, 120000), scroll: mt_rand(10, 100));
                    }
                    if (mt_rand(1, 100) <= 12) {
                        $name = self::EVENT_NAMES[mt_rand(0, \count(self::EVENT_NAMES) - 1)];
                        $drafts[] = $this->draft($site, EventType::Custom, $at->modify('+40 seconds'), $host, $path, self::CONTENT[$index], $source, $device, $country, $hashKey, $sessionKey, $visitorId, $consented, name: $name, props: ['plan' => mt_rand(0, 1) === 1 ? 'pro' : 'free']);
                    }
                    $at = $at->modify('+' . mt_rand(20, 400) . ' seconds');
                }
                $stats['events'] += \count($drafts);
                ++$stats['visits'];
                $this->handler->handle($drafts);

                if ($consented && $visitorId !== null && mt_rand(1, 100) <= 8) {
                    $this->insertConversion($site, $visitorId, $at, $source);
                    ++$stats['conversions'];
                }
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $source */
    private function insertConversion(SiteSnapshot $site, string $visitorId, \DateTimeImmutable $at, array $source): void
    {
        $utm = \is_array($source['utm']) ? $source['utm'] : [];
        $channel = $source['channel'];
        \assert($channel instanceof Channel);
        $this->connection->insert('conversions', [
            'site_id' => $site->id,
            'external_id' => 'seed-' . bin2hex(random_bytes(8)),
            'name' => 'purchase',
            'origin' => 'server',
            'occurred_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'local_day' => $at->setTimezone($site->timezone())->format('Y-m-d'),
            'received_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'visitor_id' => $visitorId,
            'value_minor' => mt_rand(1900, 29900),
            'currency' => $site->currency,
            'attr_via' => 'visitor',
            'attr_channel' => $channel->value,
            'attr_source' => \is_string($source['source'] ?? null) ? $source['source'] : null,
            'attr_utm_source' => Types::nullableString($utm['utm_source'] ?? null),
            'attr_utm_medium' => Types::nullableString($utm['utm_medium'] ?? null),
            'attr_utm_campaign' => Types::nullableString($utm['utm_campaign'] ?? null),
            'lnd_channel' => $channel->value,
        ], ['visitor_id' => ParameterType::BINARY]);
    }

    /** Reuses one of a few visitor ids so cohorts and returning visitors appear. */
    private function pickVisitor(SiteSnapshot $site, int $seed, int $index): string
    {
        $pool = 40;
        $slot = mt_rand(0, $pool - 1);

        return substr(hash('sha256', $site->id . ':' . $seed . ':' . $slot, true), 0, 16);
    }

    /**
     * @param array<string, mixed>                 $source
     * @param array{0: string, 1: string, 2: int, 3: string, 4: int} $device
     * @param array<string, string|int|float|bool> $props
     */
    private function draft(
        SiteSnapshot $site,
        EventType $type,
        \DateTimeImmutable $at,
        string $host,
        string $path,
        ?string $contentKey,
        array $source,
        array $device,
        ?string $country,
        string $hashKey,
        ?string $sessionKey,
        ?string $visitorId,
        bool $consented,
        ?string $name = null,
        array $props = [],
        ?int $engagedMs = null,
        ?int $scroll = null,
    ): EventDraft {
        $utm = \is_array($source['utm']) ? $source['utm'] : [];
        $channel = $source['channel'];
        \assert($channel instanceof Channel);
        $traffic = new Enrichment\TrafficSource(
            channel: $channel,
            source: \is_string($source['source'] ?? null) ? $source['source'] : null,
            referrerHost: \is_string($source['referrer'] ?? null) ? $source['referrer'] : null,
            utmSource: Types::nullableString($utm['utm_source'] ?? null),
            utmMedium: Types::nullableString($utm['utm_medium'] ?? null),
            utmCampaign: Types::nullableString($utm['utm_campaign'] ?? null),
            utmContent: null,
            utmTerm: null,
        );
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));

        return new EventDraft(
            siteId: $site->id,
            uid: random_bytes(12),
            type: $type,
            level: $consented ? TrackingLevel::Consented : TrackingLevel::Base,
            name: $name,
            occurredAt: $utc,
            receivedAt: $utc,
            localDay: $at->setTimezone($site->timezone())->format('Y-m-d'),
            visitorHash: VisitorHasher::toInt($hashKey),
            hashKey: $hashKey,
            sessionKey: $sessionKey,
            visitorId: $visitorId,
            host: $host,
            path: $path,
            pageHash: UrlSanitizer::pageHash($host, $path),
            query: null,
            channel: $traffic->channel,
            source: $traffic->source,
            referrerHost: $traffic->referrerHost,
            utmSource: $traffic->utmSource,
            utmMedium: $traffic->utmMedium,
            utmCampaign: $traffic->utmCampaign,
            utmContent: null,
            utmTerm: null,
            sourceKey: $traffic->key(),
            browser: $device[1],
            browserMajor: $device[2],
            os: $device[3],
            osMajor: $device[4],
            device: $device[0],
            country: $country,
            contentKey: $contentKey,
            engagedMs: $engagedMs,
            scrollPct: $scroll,
            props: $props,
            consentVersion: $consented ? 1 : 0,
            consentStat: null,
            consentReceipt: false,
        );
    }
}
