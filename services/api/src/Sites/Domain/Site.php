<?php

declare(strict_types=1);

namespace Analytics\Sites\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sites')]
#[ORM\UniqueConstraint(name: 'uniq_sites_public_key', columns: ['public_key'])]
class Site
{
    public const int MAX_VISITOR_COOKIE_DAYS = 395;
    public const array DEFAULT_QUERY_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(name: 'public_key', type: 'string', length: 24, options: ['fixed' => true])]
    public string $publicKey;

    #[ORM\Column(type: 'string', length: 190)]
    public string $name;

    #[ORM\Column(type: 'string', length: 64)]
    public string $timezone = 'UTC';

    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    public string $currency = 'EUR';

    #[ORM\Column(name: 'base_tracking_enabled', type: 'boolean')]
    public bool $baseTrackingEnabled = true;

    #[ORM\Column(name: 'visitor_hash_mode', type: 'string', length: 20, enumType: VisitorHashMode::class)]
    public VisitorHashMode $visitorHashMode = VisitorHashMode::DailyHash;

    #[ORM\Column(name: 'cookie_level_enabled', type: 'boolean')]
    public bool $cookieLevelEnabled = false;

    #[ORM\Column(name: 'cookie_domain', type: 'string', length: 190, nullable: true)]
    public ?string $cookieDomain = null;

    #[ORM\Column(name: 'visitor_cookie_days', type: 'smallint', options: ['unsigned' => true])]
    public int $visitorCookieDays = 395;

    #[ORM\Column(name: 'new_visit_on_campaign_change', type: 'boolean')]
    public bool $newVisitOnCampaignChange = true;

    #[ORM\Column(name: 'dnt_mode', type: 'string', length: 16, enumType: DntMode::class)]
    public DntMode $dntMode = DntMode::Ignore;

    #[ORM\Column(name: 'respect_gpc', type: 'boolean')]
    public bool $respectGpc = true;

    #[ORM\Column(name: 'hash_routing', type: 'boolean')]
    public bool $hashRouting = false;

    #[ORM\Column(name: 'allow_localhost', type: 'boolean')]
    public bool $allowLocalhost = false;

    #[ORM\Column(name: 'tracker_global', type: 'string', length: 32)]
    public string $trackerGlobal = 'analytics';

    /** @var list<string> */
    #[ORM\Column(name: 'allowed_query_params', type: 'json')]
    public array $allowedQueryParams = self::DEFAULT_QUERY_PARAMS;

    /** @var list<string> */
    #[ORM\Column(name: 'excluded_paths', type: 'json')]
    public array $excludedPaths = [];

    /** @var list<string> */
    #[ORM\Column(name: 'excluded_ip_prefixes', type: 'json')]
    public array $excludedIpPrefixes = [];

    /** @var list<string> event names counted as "contacts" in content stats */
    #[ORM\Column(name: 'content_contact_events', type: 'json')]
    public array $contentContactEvents = [];

    /** @var array{outbound?: bool, downloads?: bool, forms?: bool} */
    #[ORM\Column(name: 'auto_events', type: 'json')]
    public array $autoEvents = [];

    #[ORM\Column(name: 'min_group_size', type: 'smallint', options: ['unsigned' => true])]
    public int $minGroupSize = 5;

    #[ORM\Column(name: 'consent_receipts_enabled', type: 'boolean')]
    public bool $consentReceiptsEnabled = false;

    #[ORM\Column(name: 'rollup_version', type: 'integer', options: ['unsigned' => true])]
    public int $rollupVersion = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $archivedAt = null;

    /** @var Collection<int, SiteDomain> */
    #[ORM\OneToMany(targetEntity: SiteDomain::class, mappedBy: 'site', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['host' => 'ASC'])]
    public Collection $domains;

    public function __construct(string $publicKey, string $name, \DateTimeImmutable $now)
    {
        $this->publicKey = $publicKey;
        $this->name = $name;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->domains = new ArrayCollection();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Site not persisted.');
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function addDomain(string $host, bool $includeSubdomains, \DateTimeImmutable $now): SiteDomain
    {
        foreach ($this->domains as $domain) {
            if ($domain->host === $host) {
                $domain->includeSubdomains = $includeSubdomains;

                return $domain;
            }
        }
        $domain = new SiteDomain($this, $host, $includeSubdomains, $now);
        $this->domains->add($domain);

        return $domain;
    }

    public function removeDomain(string $host): bool
    {
        foreach ($this->domains as $key => $domain) {
            if ($domain->host === $host) {
                $this->domains->remove($key);

                return true;
            }
        }

        return false;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }
}
