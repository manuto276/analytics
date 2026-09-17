<?php

declare(strict_types=1);

namespace Analytics\Sites\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_domains')]
#[ORM\UniqueConstraint(name: 'uniq_site_domains_site_host', columns: ['site_id', 'host'])]
#[ORM\Index(name: 'idx_site_domains_host', columns: ['host'])]
class SiteDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'domains')]
    #[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Site $site;

    #[ORM\Column(type: 'string', length: 190)]
    public string $host;

    #[ORM\Column(name: 'include_subdomains', type: 'boolean')]
    public bool $includeSubdomains;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct(Site $site, string $host, bool $includeSubdomains, \DateTimeImmutable $now)
    {
        $this->site = $site;
        $this->host = $host;
        $this->includeSubdomains = $includeSubdomains;
        $this->createdAt = $now;
    }
}
