<?php

declare(strict_types=1);

namespace Analytics\Sites\Application;

use Analytics\Sites\Domain\DntMode;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\VisitorHashMode;

/**
 * Immutable, cacheable view of a site's tracking configuration used on the ingestion hot path.
 */
final readonly class SiteSnapshot
{
    /**
     * @param list<array{host: string, include_subdomains: bool}> $domains
     * @param list<string>                                         $allowedQueryParams
     * @param list<string>                                         $excludedPaths
     * @param list<string>                                         $excludedIpPrefixes
     * @param list<string>                                         $contentContactEvents
     * @param array{outbound?: bool, downloads?: bool, forms?: bool} $autoEvents
     */
    public function __construct(
        public int $id,
        public string $publicKey,
        public string $name,
        public string $timezone,
        public string $currency,
        public bool $baseTrackingEnabled,
        public VisitorHashMode $visitorHashMode,
        public bool $cookieLevelEnabled,
        public ?string $cookieDomain,
        public int $visitorCookieDays,
        public bool $newVisitOnCampaignChange,
        public DntMode $dntMode,
        public bool $respectGpc,
        public bool $hashRouting,
        public bool $allowLocalhost,
        public string $trackerGlobal,
        public array $domains,
        public array $allowedQueryParams,
        public array $excludedPaths,
        public array $excludedIpPrefixes,
        public array $contentContactEvents,
        public array $autoEvents,
        public int $minGroupSize,
        public bool $consentReceiptsEnabled,
        public bool $archived,
    ) {}

    public static function fromSite(Site $site): self
    {
        $domains = [];
        foreach ($site->domains as $domain) {
            $domains[] = ['host' => $domain->host, 'include_subdomains' => $domain->includeSubdomains];
        }

        return new self(
            id: $site->id(),
            publicKey: $site->publicKey,
            name: $site->name,
            timezone: $site->timezone,
            currency: $site->currency,
            baseTrackingEnabled: $site->baseTrackingEnabled,
            visitorHashMode: $site->visitorHashMode,
            cookieLevelEnabled: $site->cookieLevelEnabled,
            cookieDomain: $site->cookieDomain,
            visitorCookieDays: $site->visitorCookieDays,
            newVisitOnCampaignChange: $site->newVisitOnCampaignChange,
            dntMode: $site->dntMode,
            respectGpc: $site->respectGpc,
            hashRouting: $site->hashRouting,
            allowLocalhost: $site->allowLocalhost,
            trackerGlobal: $site->trackerGlobal,
            domains: $domains,
            allowedQueryParams: $site->allowedQueryParams,
            excludedPaths: $site->excludedPaths,
            excludedIpPrefixes: $site->excludedIpPrefixes,
            contentContactEvents: $site->contentContactEvents,
            autoEvents: $site->autoEvents,
            minGroupSize: $site->minGroupSize,
            consentReceiptsEnabled: $site->consentReceiptsEnabled,
            archived: $site->isArchived(),
        );
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    public function isOwnHost(string $host): bool
    {
        return DomainMatcher::matches($host, $this->domains);
    }
}
