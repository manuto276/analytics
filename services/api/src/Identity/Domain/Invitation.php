<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invitations')]
#[ORM\UniqueConstraint(name: 'uniq_invitations_token_hash', columns: ['token_hash'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<array{site_id: int, role: string}> $siteRoles
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 190)]
        public string $email,
        #[ORM\Column(name: 'token_hash', type: 'binary', length: 32, options: ['fixed' => true])]
        public string $tokenHash,
        #[ORM\Column(name: 'global_role', type: 'string', length: 16, enumType: GlobalRole::class)]
        public GlobalRole $globalRole,
        #[ORM\Column(name: 'site_roles', type: 'json')]
        public array $siteRoles,
        #[ORM\Column(name: 'invited_by', type: 'integer', nullable: true, options: ['unsigned' => true])]
        public ?int $invitedBy,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Invitation not persisted.');
    }

    public function status(\DateTimeImmutable $now): string
    {
        return match (true) {
            $this->acceptedAt !== null => 'accepted',
            $this->revokedAt !== null => 'revoked',
            $this->expiresAt <= $now => 'expired',
            default => 'pending',
        };
    }
}
