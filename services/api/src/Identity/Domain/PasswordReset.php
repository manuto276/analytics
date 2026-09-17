<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'password_resets')]
#[ORM\Index(name: 'idx_password_resets_user', columns: ['user_id'])]
class PasswordReset
{
    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'token_hash', type: 'binary', length: 32, options: ['fixed' => true])]
        public string $tokenHash,
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $expiresAt,
    ) {}
}
