<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A requested, not yet confirmed change of a user's sign-in address. Like password_resets, only the
 * SHA-256 of the token is stored, the token expires and works once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_changes')]
#[ORM\Index(name: 'idx_email_changes_user', columns: ['user_id'])]
class EmailChange
{
    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'token_hash', type: 'binary', length: 32, options: ['fixed' => true])]
        public string $tokenHash,
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Column(name: 'new_email', type: 'string', length: 190)]
        public string $newEmail,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}
}
