<?php

declare(strict_types=1);

namespace Analytics\Consent\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A revision of a site's consent banner configuration. Published revisions are immutable;
 * consent_version increases only on material changes (and then every visitor is asked again).
 */
#[ORM\Entity]
#[ORM\Table(name: 'consent_configs')]
#[ORM\UniqueConstraint(name: 'uniq_consent_configs_site_revision', columns: ['site_id', 'revision'])]
#[ORM\Index(name: 'idx_consent_configs_site_status', columns: ['site_id', 'status'])]
class ConsentConfig
{
    public const string DRAFT = 'draft';
    public const string PUBLISHED = 'published';
    public const string ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'published_by', type: 'integer', nullable: true, options: ['unsigned' => true])]
    public ?int $publishedBy = null;

    /**
     * @param array<string, array<string, string>> $texts      locale => text keys
     * @param array<string, string>                $policyUrls locale => URL
     * @param array<array-key, mixed>              $theme      v1 or v2 (ConsentThemeV2::fromStored() reads both)
     */
    public function __construct(
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
        public int $revision,
        #[ORM\Column(name: 'consent_version', type: 'integer', options: ['unsigned' => true])]
        public int $consentVersion,
        #[ORM\Column(type: 'string', length: 16)]
        public string $status,
        #[ORM\Column(type: 'json')]
        public array $texts,
        #[ORM\Column(name: 'policy_urls', type: 'json')]
        public array $policyUrls,
        #[ORM\Column(name: 'default_locale', type: 'string', length: 8)]
        public string $defaultLocale,
        #[ORM\Column(type: 'json')]
        public array $theme,
        #[ORM\Column(name: 'accepted_ttl_days', type: 'smallint', options: ['unsigned' => true])]
        public int $acceptedTtlDays,
        #[ORM\Column(name: 'rejected_ttl_days', type: 'smallint', options: ['unsigned' => true])]
        public int $rejectedTtlDays,
        #[ORM\Column(name: 'show_floating_reopen', type: 'boolean')]
        public bool $showFloatingReopen,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Consent config not persisted.');
    }
}
