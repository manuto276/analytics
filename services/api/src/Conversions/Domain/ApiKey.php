<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
#[ORM\UniqueConstraint(name: 'uniq_api_keys_prefix', columns: ['prefix'])]
#[ORM\Index(name: 'idx_api_keys_site', columns: ['site_id'])]
class ApiKey
{
    public const array SCOPES = ['conversions:write', 'stats:read', 'reports:read'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    /** @param list<string> $scopes */
    public function __construct(
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(type: 'string', length: 120)]
        public string $name,
        #[ORM\Column(type: 'string', length: 8, options: ['fixed' => true])]
        public string $prefix,
        #[ORM\Column(name: 'secret_hash', type: 'binary', length: 32, options: ['fixed' => true])]
        public string $secretHash,
        #[ORM\Column(type: 'json')]
        public array $scopes,
        #[ORM\Column(name: 'created_by', type: 'integer', nullable: true, options: ['unsigned' => true])]
        public ?int $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('API key not persisted.');
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
