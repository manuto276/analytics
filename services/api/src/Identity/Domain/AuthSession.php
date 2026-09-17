<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auth_sessions')]
#[ORM\Index(name: 'idx_auth_sessions_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_auth_sessions_absolute', columns: ['absolute_expires_at'])]
class AuthSession
{
    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        /** SHA-256 of the session token. */
        #[ORM\Id]
        #[ORM\Column(type: 'binary', length: 32, options: ['fixed' => true])]
        public string $id,
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Column(type: 'string', length: 16, enumType: SessionState::class)]
        public SessionState $state,
        #[ORM\Column(name: 'csrf_secret', type: 'string', length: 64)]
        public string $csrfSecret,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $lastSeenAt,
        #[ORM\Column(name: 'idle_expires_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $idleExpiresAt,
        #[ORM\Column(name: 'absolute_expires_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $absoluteExpiresAt,
        #[ORM\Column(name: 'ip_prefix', type: 'string', length: 64, nullable: true)]
        public ?string $ipPrefix,
        #[ORM\Column(name: 'ua_summary', type: 'string', length: 120, nullable: true)]
        public ?string $uaSummary,
    ) {}

    public function isValid(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->idleExpiresAt > $now && $this->absoluteExpiresAt > $now;
    }
}
