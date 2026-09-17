<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_costs')]
#[ORM\Index(name: 'idx_campaign_costs_site_day', columns: ['site_id', 'day_from'])]
class CampaignCost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(name: 'day_from', type: 'date_immutable')]
        public \DateTimeImmutable $dayFrom,
        #[ORM\Column(name: 'day_to', type: 'date_immutable')]
        public \DateTimeImmutable $dayTo,
        #[ORM\Column(type: 'string', length: 32, nullable: true)]
        public ?string $channel,
        #[ORM\Column(name: 'utm_source', type: 'string', length: 100, nullable: true)]
        public ?string $utmSource,
        #[ORM\Column(name: 'utm_medium', type: 'string', length: 100, nullable: true)]
        public ?string $utmMedium,
        #[ORM\Column(name: 'utm_campaign', type: 'string', length: 100, nullable: true)]
        public ?string $utmCampaign,
        #[ORM\Column(name: 'amount_minor', type: 'bigint')]
        public string $amountMinor,
        #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
        public string $currency,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $note,
        #[ORM\Column(name: 'import_batch_id', type: 'string', length: 36, nullable: true)]
        public ?string $importBatchId,
        #[ORM\Column(name: 'created_by', type: 'integer', nullable: true, options: ['unsigned' => true])]
        public ?int $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Cost not persisted.');
    }
}
