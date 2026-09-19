<?php

declare(strict_types=1);

namespace Analytics\Sites\Application;

use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Net\IpTruncator;
use Analytics\Shared\Validation\Input;
use Analytics\Sites\Domain\DntMode;
use Analytics\Sites\Domain\DomainMatcher;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\VisitorHashMode;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SiteService
{
    public const string NAME_PATTERN = '/^[a-z0-9_:.-]{1,64}$/';

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private SiteRepository $sites,
        private ClockInterface $clock,
    ) {}

    public static function generatePublicKey(): string
    {
        return 'pk_' . TokenGenerator::alphanumeric(21);
    }

    /**
     * @param list<array{host: string, include_subdomains: bool}> $domains
     */
    public function create(string $name, array $domains, string $timezone = 'UTC', VisitorHashMode $hashMode = VisitorHashMode::DailyHash, ?string $cookieDomain = null, string $currency = 'EUR'): Site
    {
        $input = new Input([
            'name' => $name,
            'timezone' => $timezone,
            'currency' => $currency,
            'visitor_hash_mode' => $hashMode->value,
            'cookie_domain' => $cookieDomain,
            'domains' => $domains,
        ]);
        $site = new Site(self::generatePublicKey(), $name, $this->clock->now());
        $this->apply($site, $input, true);
        $input->assertValid();
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }

    public function createFromInput(Input $input): Site
    {
        $site = new Site(self::generatePublicKey(), '', $this->clock->now());
        $this->apply($site, $input, true);
        $input->assertValid();
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }

    /** @return array{timezone_changed: bool, reports_invalidated: bool} */
    public function update(Site $site, Input $input): array
    {
        $oldTimezone = $site->timezone;
        $before = self::reportingSettings($site);
        $this->apply($site, $input, false);
        $input->assertValid();
        $site->updatedAt = $this->clock->now();
        // These settings feed numbers that reports have already computed and cached, so the cache key
        // has to move with them. Rollups keep the old numbers until the days are rebuilt.
        $invalidated = $before !== self::reportingSettings($site);
        $this->em->flush();
        if ($invalidated) {
            // Rollup runs bump the same counter straight in SQL, so the loaded entity may be behind:
            // increment in the database and resync rather than writing a stale value back.
            $this->bumpRollupVersion($site->id());
            $this->em->refresh($site);
        }
        $this->sites->forgetSnapshot($site);

        return ['timezone_changed' => $oldTimezone !== $site->timezone, 'reports_invalidated' => $invalidated];
    }

    public function archive(Site $site): void
    {
        $site->archivedAt ??= $this->clock->now();
        $site->updatedAt = $this->clock->now();
        $this->em->flush();
        $this->sites->forgetSnapshot($site);
    }

    public function bumpRollupVersion(int $siteId): void
    {
        $this->connection->executeStatement('UPDATE sites SET rollup_version = rollup_version + 1 WHERE id = ?', [$siteId]);
    }

    /** Settings that change the numbers a stored report already holds. */
    private static function reportingSettings(Site $site): string
    {
        $contacts = $site->contentContactEvents;
        sort($contacts);

        return implode('|', [$site->timezone, $site->currency, (string) $site->minGroupSize, ...$contacts]);
    }

    private function apply(Site $site, Input $input, bool $creating): void
    {
        if ($creating || $input->has('name')) {
            $site->name = $input->string('name', 190);
        }
        if ($input->has('timezone')) {
            $timezone = $input->string('timezone', 64);
            if ($timezone !== '' && !\in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
                $input->error('timezone', 'Must be a valid IANA time zone.');
            } elseif ($timezone !== '') {
                $site->timezone = $timezone;
            }
        }
        if ($input->has('currency')) {
            $site->currency = $input->string('currency', 3, 3, '/^[A-Z]{3}$/');
        }
        foreach ([
            'base_tracking_enabled' => 'baseTrackingEnabled',
            'cookie_level_enabled' => 'cookieLevelEnabled',
            'new_visit_on_campaign_change' => 'newVisitOnCampaignChange',
            'respect_gpc' => 'respectGpc',
            'hash_routing' => 'hashRouting',
            'allow_localhost' => 'allowLocalhost',
            'consent_receipts_enabled' => 'consentReceiptsEnabled',
        ] as $key => $property) {
            if ($input->has($key)) {
                $site->{$property} = $input->bool($key);
            }
        }
        if ($input->has('visitor_hash_mode')) {
            $mode = $input->enum('visitor_hash_mode', VisitorHashMode::class);
            if ($mode instanceof VisitorHashMode) {
                $site->visitorHashMode = $mode;
            }
        }
        if ($input->has('dnt_mode')) {
            $mode = $input->enum('dnt_mode', DntMode::class);
            if ($mode instanceof DntMode) {
                $site->dntMode = $mode;
            }
        }
        if ($input->has('visitor_cookie_days')) {
            $site->visitorCookieDays = $input->int('visitor_cookie_days', null, 1, Site::MAX_VISITOR_COOKIE_DAYS);
        }
        if (!$creating && $input->has('archived')) {
            $archived = $input->bool('archived');
            $site->archivedAt = $archived ? ($site->archivedAt ?? $this->clock->now()) : null;
        }
        if ($input->has('min_group_size')) {
            $site->minGroupSize = $input->int('min_group_size', null, 1, 1000);
        }
        if ($input->has('tracker_global')) {
            $site->trackerGlobal = $input->string('tracker_global', 32, 1, '/^[A-Za-z_$][A-Za-z0-9_$]{0,31}$/');
        }
        if ($input->has('allowed_query_params')) {
            $site->allowedQueryParams = $input->stringList('allowed_query_params', 50, 32, '/^[A-Za-z0-9_.\-\[\]]{1,32}$/');
        }
        if ($input->has('excluded_paths')) {
            $site->excludedPaths = $input->stringList('excluded_paths', 100, 255, '#^/\S*$#');
        }
        if ($input->has('excluded_ip_prefixes')) {
            $prefixes = $input->stringList('excluded_ip_prefixes', 100, 64);
            foreach ($prefixes as $i => $prefix) {
                $address = explode('/', $prefix, 2)[0];
                if (IpTruncator::truncate($address) === null) {
                    $input->error('excluded_ip_prefixes.' . $i, 'Must be an IP address or CIDR range.');
                }
            }
            $site->excludedIpPrefixes = $prefixes;
        }
        if ($input->has('content_contact_events')) {
            $site->contentContactEvents = $input->stringList('content_contact_events', 20, 64, self::NAME_PATTERN);
        }
        if ($input->has('auto_events')) {
            $auto = $input->nested('auto_events');
            if ($auto !== null) {
                $site->autoEvents = [
                    'outbound' => $auto->bool('outbound', false),
                    'downloads' => $auto->bool('downloads', false),
                    'forms' => $auto->bool('forms', false),
                ];
                $input->merge($auto);
            }
        }
        if ($creating || $input->has('domains')) {
            $this->applyDomains($site, $input, $creating);
        }
        if ($input->has('cookie_domain')) {
            $raw = $input->optionalString('cookie_domain', 190);
            if ($raw === null || $raw === '') {
                $site->cookieDomain = null;
            } else {
                $host = DomainMatcher::normalizeHost(ltrim($raw, '.'));
                if ($host === null) {
                    $input->error('cookie_domain', 'Must be a domain name.');
                } else {
                    $ok = false;
                    foreach ($site->domains as $domain) {
                        if ($domain->host === $host || str_ends_with($domain->host, '.' . $host)) {
                            $ok = true;
                        }
                    }
                    if (!$ok) {
                        $input->error('cookie_domain', 'Must be one of the site domains or a parent domain of them.');
                    }
                    $site->cookieDomain = $host;
                }
            }
        }
    }

    private function applyDomains(Site $site, Input $input, bool $creating): void
    {
        $raw = $input->array('domains', $creating);
        if ($raw === null) {
            return;
        }
        if (!array_is_list($raw) || $raw === [] || \count($raw) > 50) {
            $input->error('domains', 'Must be a list of 1 to 50 domains.');

            return;
        }
        $wanted = [];
        foreach ($raw as $i => $entry) {
            if (\is_string($entry)) {
                $entry = ['host' => $entry, 'include_subdomains' => false];
            }
            if (!\is_array($entry) || !\is_string($entry['host'] ?? null)) {
                $input->error('domains.' . $i, 'Must be an object with host and include_subdomains.');
                continue;
            }
            $parsed = DomainMatcher::parseEntry($entry['host'], (bool) ($entry['include_subdomains'] ?? false));
            if ($parsed === null) {
                $input->error('domains.' . $i . '.host', 'Must be a valid host name (optionally prefixed with "*." to include subdomains).');
                continue;
            }
            // "*.example.com" and "example.com" in the same list are one domain: subdomains win.
            $wanted[$parsed['host']] = ($wanted[$parsed['host']] ?? false) || $parsed['include_subdomains'];
        }
        $now = $this->clock->now();
        foreach ($site->domains->toArray() as $existing) {
            if (!isset($wanted[$existing->host])) {
                $site->removeDomain($existing->host);
            }
        }
        foreach ($wanted as $host => $subdomains) {
            $site->addDomain($host, $subdomains, $now);
        }
    }

    /** @return array<string, mixed> */
    public static function toArray(Site $site, ?string $role = null): array
    {
        $domains = [];
        foreach ($site->domains as $domain) {
            $domains[] = ['host' => $domain->host, 'include_subdomains' => $domain->includeSubdomains];
        }

        return [
            'id' => $site->id(),
            'public_key' => $site->publicKey,
            'name' => $site->name,
            'timezone' => $site->timezone,
            'currency' => $site->currency,
            'domains' => $domains,
            'base_tracking_enabled' => $site->baseTrackingEnabled,
            'visitor_hash_mode' => $site->visitorHashMode->value,
            'cookie_level_enabled' => $site->cookieLevelEnabled,
            'cookie_domain' => $site->cookieDomain,
            'visitor_cookie_days' => $site->visitorCookieDays,
            'new_visit_on_campaign_change' => $site->newVisitOnCampaignChange,
            'dnt_mode' => $site->dntMode->value,
            'respect_gpc' => $site->respectGpc,
            'hash_routing' => $site->hashRouting,
            'allow_localhost' => $site->allowLocalhost,
            'tracker_global' => $site->trackerGlobal,
            'allowed_query_params' => $site->allowedQueryParams,
            'excluded_paths' => $site->excludedPaths,
            'excluded_ip_prefixes' => $site->excludedIpPrefixes,
            'content_contact_events' => $site->contentContactEvents,
            'auto_events' => (object) $site->autoEvents,
            'min_group_size' => $site->minGroupSize,
            'consent_receipts_enabled' => $site->consentReceiptsEnabled,
            'archived' => $site->isArchived(),
            'created_at' => $site->createdAt->format(\DATE_ATOM),
            'updated_at' => $site->updatedAt->format(\DATE_ATOM),
            'role' => $role,
        ];
    }
}
