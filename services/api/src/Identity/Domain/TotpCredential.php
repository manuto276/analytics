<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'totp_credentials')]
class TotpCredential
{
    #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'last_used_step', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    public ?string $lastUsedStep = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Column(name: 'secret_ciphertext', type: 'string', length: 255)]
        public string $secretCiphertext,
        #[ORM\Column(name: 'key_id', type: 'string', length: 8)]
        public string $keyId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }
}
