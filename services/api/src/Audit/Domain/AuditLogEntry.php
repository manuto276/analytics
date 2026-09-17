<?php

declare(strict_types=1);

namespace Analytics\Audit\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_site_time', columns: ['site_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_actor_time', columns: ['actor_type', 'actor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_time', columns: ['occurred_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    public ?string $id = null;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $occurredAt,
        /** user | api_key | console | system */
        #[ORM\Column(name: 'actor_type', type: 'string', length: 16)]
        public string $actorType,
        #[ORM\Column(name: 'actor_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
        public ?int $actorId,
        #[ORM\Column(type: 'string', length: 64)]
        public string $action,
        #[ORM\Column(name: 'site_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
        public ?int $siteId,
        #[ORM\Column(name: 'target_type', type: 'string', length: 32, nullable: true)]
        public ?string $targetType,
        #[ORM\Column(name: 'target_id', type: 'string', length: 64, nullable: true)]
        public ?string $targetId,
        #[ORM\Column(type: 'json')]
        public array $metadata,
        #[ORM\Column(name: 'ip_prefix', type: 'string', length: 64, nullable: true)]
        public ?string $ipPrefix,
    ) {}
}
