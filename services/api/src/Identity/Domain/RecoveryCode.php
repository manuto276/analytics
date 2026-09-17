<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recovery_codes')]
#[ORM\Index(name: 'idx_recovery_codes_user', columns: ['user_id'])]
class RecoveryCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Column(name: 'code_hash', type: 'binary', length: 32, options: ['fixed' => true])]
        public string $codeHash,
    ) {}
}
