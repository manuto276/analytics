<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 190)]
    public string $email;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    public string $passwordHash;

    #[ORM\Column(name: 'display_name', type: 'string', length: 120)]
    public string $displayName;

    #[ORM\Column(name: 'global_role', type: 'string', length: 16, enumType: GlobalRole::class)]
    public GlobalRole $globalRole;

    #[ORM\Column(type: 'string', length: 8)]
    public string $locale = 'en';

    #[ORM\Column(type: 'string', length: 16, enumType: UserStatus::class)]
    public UserStatus $status = UserStatus::Active;

    #[ORM\Column(name: 'failed_logins', type: 'smallint', options: ['unsigned' => true])]
    public int $failedLogins = 0;

    #[ORM\Column(name: 'locked_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(name: 'password_changed_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $passwordChangedAt;

    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $passwordHash, string $displayName, GlobalRole $globalRole, \DateTimeImmutable $now)
    {
        $this->email = self::normalizeEmail($email);
        $this->passwordHash = $passwordHash;
        $this->displayName = $displayName;
        $this->globalRole = $globalRole;
        $this->passwordChangedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('User not persisted.');
    }

    public function isAdmin(): bool
    {
        return $this->globalRole === GlobalRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
