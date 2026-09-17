<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Net\IpPrefix;
use Analytics\Shared\RateLimit\RateLimiter;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Domain\DntMode;
use Analytics\Sites\Domain\DomainMatcher;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Sites\Domain\VisitorHashMode;
use Analytics\Tracking\Application\Enrichment\BotFilter;
use Analytics\Tracking\Application\Enrichment\ChannelClassifier;
use Analytics\Tracking\Application\Enrichment\PiiScrubber;
use Analytics\Tracking\Application\Enrichment\TrafficSource;
use Analytics\Tracking\Application\Enrichment\UrlSanitizer;
use Analytics\Tracking\Application\Enrichment\UserAgentClassifier;
use Analytics\Tracking\Application\Payload\ParsedEvent;
use Analytics\Tracking\Application\Payload\ParsedPayload;
use Analytics\Tracking\Application\Payload\PayloadException;
use Analytics\Tracking\Application\Payload\PayloadParser;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;
use Psr\Clock\ClockInterface;

/**
 * HTTP-side ingestion: validation, origin and rate-limit checks, bot filtering and in-memory
 * enrichment. The full User-Agent is used only inside this call.
 */
final readonly class CollectService
{
    public function __construct(
        private PayloadParser $parser,
        private SiteRepository $sites,
        private RateLimiter $rateLimiter,
        private UserAgentClassifier $userAgents,
        private ChannelClassifier $channels,
        private GeoLocator $geo,
        private DailySaltProvider $salts,
        private EventSink $sink,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array{site: SiteSnapshot, accepted: int, reason: ?string} reason explains silently dropped batches
     */
    public function collect(array $body, CollectContext $context): array
    {
        try {
            $payload = $this->parser->parse($body);
        } catch (PayloadException $e) {
            throw new ApiProblem(400, $e->problemCode, 'Bad request', $e->getMessage());
        }

        $site = $this->sites->snapshotByPublicKey($payload->publicKey);
        if ($site === null || $site->archived) {
            throw new ApiProblem(404, 'unknown_site', 'Not found', 'Unknown site key.');
        }
        if (!$this->originAllowed($site, $context)) {
            throw new ApiProblem(403, 'origin_not_allowed', 'Forbidden', 'This origin is not allowed to send events for this site.');
        }

        $this->rateLimiter->enforce('collect', $site->id . ':' . ($context->ipPrefix === null ? '-' : (string) $context->ipPrefix));
        $this->rateLimiter->enforce('collect_site', (string) $site->id);

        $ua = $this->userAgents->classify($context->userAgent, $payload->screenWidth);
        $rejection = BotFilter::requestRejection($ua, $context->acceptLanguage, $context->ipPrefix, $site);
        if ($rejection !== null) {
            return ['site' => $site, 'accepted' => 0, 'reason' => $rejection];
        }

        if ($context->doNotTrack && $site->dntMode === DntMode::NoTracking) {
            return ['site' => $site, 'accepted' => 0, 'reason' => 'dnt'];
        }
        if ($payload->level === TrackingLevel::Consented && (
            !$site->cookieLevelEnabled
            || ($context->doNotTrack && $site->dntMode === DntMode::NoCookie)
            || ($context->globalPrivacyControl && $site->respectGpc)
        )) {
            $payload = $payload->withBaseLevel();
        }

        $now = $this->clock->now();
        $hash = null;
        if ($site->visitorHashMode === VisitorHashMode::DailyHash) {
            $hash = VisitorHasher::hash($site->id, $context->ipPrefix, $context->userAgent, $this->salts->saltFor($now));
        }
        $country = $this->geo->country($context->ipPrefix);
        $isOwnHost = static fn(string $host): bool => $site->isOwnHost($host);

        $drafts = [];
        foreach ($payload->events as $event) {
            $draft = $this->draft($site, $payload, $event, $ua, $hash, $country, $now, $isOwnHost);
            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }
        $this->sink->accept($drafts);

        return ['site' => $site, 'accepted' => \count($drafts), 'reason' => null];
    }

    /**
     * @param callable(string): bool $isOwnHost
     */
    private function draft(SiteSnapshot $site, ParsedPayload $payload, ParsedEvent $event, Enrichment\UserAgentInfo $ua, ?string $hash, ?string $country, \DateTimeImmutable $now, callable $isOwnHost): ?EventDraft
    {
        $isStat = $event->type === EventType::ConsentStat;
        if (!$site->baseTrackingEnabled && $payload->level === TrackingLevel::Base && !$isStat) {
            return null;
        }
        if ($event->type === EventType::ConsentUpgrade && $payload->level !== TrackingLevel::Consented) {
            return null;
        }

        $pageUrl = $event->type === EventType::ConsentUpgrade ? ($event->landingUrl ?? $event->url) : $event->url;
        $page = UrlSanitizer::sanitize($pageUrl, $site->allowedQueryParams, $site->hashRouting);
        if ($page === null || !($site->isOwnHost($page['host']) || ($site->allowLocalhost && self::isLocal($page['host'])))) {
            return null;
        }
        if (!$isStat && BotFilter::isExcludedPath($page['path'], $site->excludedPaths)) {
            return null;
        }

        $traffic = match ($event->type) {
            EventType::Pageview => $this->channels->classify($page['params'], $event->referrer, $isOwnHost),
            EventType::ConsentUpgrade => $this->channels->classify($page['params'], $event->landingReferrer, $isOwnHost),
            default => TrafficSource::direct(),
        };

        $occurred = $now->modify('-' . $event->ageMs . ' milliseconds');
        $localDay = $occurred->setTimezone($site->timezone())->format('Y-m-d');
        $consented = $payload->level === TrackingLevel::Consented;

        $props = [];
        foreach ($event->props as $key => $value) {
            // Scrubbing runs after parsing and rewrites the key, so what is stored has to be checked
            // against the column that stores it, not against the key the parser accepted.
            $scrubbed = PiiScrubber::scrubPropKey($key, PayloadParser::MAX_PROP_KEY);
            if ($scrubbed === null) {
                continue;
            }
            $props[$scrubbed] = \is_string($value) ? PiiScrubber::scrub($value) : $value;
        }

        return new EventDraft(
            siteId: $site->id,
            uid: $event->uid,
            type: $event->type,
            level: $payload->level,
            name: $event->name,
            occurredAt: $occurred,
            receivedAt: $now,
            localDay: $localDay,
            visitorHash: $hash === null ? null : VisitorHasher::toInt($hash),
            hashKey: $hash,
            sessionKey: $consented ? $payload->sessionId : null,
            visitorId: $consented ? $payload->visitorId : null,
            host: $page['host'],
            path: $page['path'],
            pageHash: UrlSanitizer::pageHash($page['host'], $page['path']),
            query: $page['query'],
            channel: $traffic->channel,
            source: $traffic->source,
            referrerHost: $traffic->referrerHost,
            utmSource: $traffic->utmSource,
            utmMedium: $traffic->utmMedium,
            utmCampaign: $traffic->utmCampaign,
            utmContent: $traffic->utmContent,
            utmTerm: $traffic->utmTerm,
            sourceKey: $traffic->key(),
            browser: $ua->browser,
            browserMajor: $ua->browserMajor,
            os: $ua->os,
            osMajor: $ua->osMajor,
            device: $ua->device,
            country: $country,
            contentKey: $event->contentKey,
            engagedMs: $event->engagedMs,
            scrollPct: $event->scrollPct,
            props: $props,
            consentVersion: $payload->consentVersion,
            consentStat: $event->consentStat,
            consentReceipt: $event->type === EventType::ConsentUpgrade && $site->consentReceiptsEnabled,
        );
    }

    public function originAllowed(SiteSnapshot $site, CollectContext $context): bool
    {
        $host = DomainMatcher::hostOf($context->origin) ?? DomainMatcher::hostOf($context->referer);
        if ($host === null) {
            return false;
        }

        return $site->isOwnHost($host) || ($site->allowLocalhost && self::isLocal($host));
    }

    private static function isLocal(string $host): bool
    {
        return \in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
    }

    /** For the forget endpoint. */
    public function ipKey(?IpPrefix $ip): string
    {
        return $ip === null ? '-' : (string) $ip;
    }
}
