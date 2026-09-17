<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Identity\Domain\UserStatus;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class UserService
{
    public const array LOCALES = ['en', 'it'];

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private PasswordHasher $hasher,
        private ClockInterface $clock,
    ) {}

    public function findByEmail(string $email): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);

        return $user instanceof User ? $user : null;
    }

    public function get(int $id): User
    {
        $user = $this->em->find(User::class, $id);

        return $user instanceof User ? $user : throw ApiProblem::notFound('User not found.');
    }

    public function create(string $email, string $password, string $displayName, GlobalRole $role, string $locale = 'en'): User
    {
        if ($this->findByEmail($email) !== null) {
            throw ApiProblem::conflict('email_taken', 'A user with this email already exists.');
        }
        $violations = PasswordHasher::validatePolicy($password, $email);
        if ($violations !== []) {
            throw ApiProblem::validation(['password' => $violations]);
        }
        $user = new User($email, $this->hasher->hash($password), $displayName, $role, $this->clock->now());
        $user->locale = \in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function setPassword(User $user, string $password): void
    {
        $violations = PasswordHasher::validatePolicy($password, $user->email);
        if ($violations !== []) {
            throw ApiProblem::validation(['password' => $violations]);
        }
        $now = $this->clock->now();
        $user->passwordHash = $this->hasher->hash($password);
        $user->passwordChangedAt = $now;
        $user->updatedAt = $now;
        $user->failedLogins = 0;
        $user->lockedUntil = null;
        $this->em->flush();
    }

    public function save(User $user): void
    {
        $user->updatedAt = $this->clock->now();
        $this->em->flush();
    }

    public function setStatus(User $user, UserStatus $status): void
    {
        if ($status === UserStatus::Disabled && $user->isAdmin() && $this->activeAdminCount() <= 1) {
            throw ApiProblem::conflict('last_admin', 'The last active admin cannot be disabled.');
        }
        $user->status = $status;
        $user->updatedAt = $this->clock->now();
        $this->em->flush();
    }

    public function setGlobalRole(User $user, GlobalRole $role): void
    {
        if ($user->isAdmin() && $role !== GlobalRole::Admin && $this->activeAdminCount() <= 1) {
            throw ApiProblem::conflict('last_admin', 'The last active admin cannot be demoted.');
        }
        $user->globalRole = $role;
        $user->updatedAt = $this->clock->now();
        $this->em->flush();
    }

    public function activeAdminCount(): int
    {
        return Types::int($this->connection->fetchOne("SELECT COUNT(*) FROM users WHERE global_role = 'admin' AND status = 'active'"));
    }

    public function setSiteRole(int $userId, int $siteId, ?SiteRole $role): void
    {
        $this->connection->executeStatement('DELETE FROM user_site_roles WHERE user_id = ? AND site_id = ?', [$userId, $siteId]);
        if ($role !== null) {
            $this->connection->insert('user_site_roles', [
                'user_id' => $userId,
                'site_id' => $siteId,
                'role' => $role->value,
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @return list<array{site_id: int, role: string}> */
    public function siteRoles(int $userId): array
    {
        return array_map(
            static fn(array $r): array => ['site_id' => Types::int($r['site_id']), 'role' => Types::string($r['role'])],
            $this->connection->fetchAllAssociative('SELECT site_id, role FROM user_site_roles WHERE user_id = ? ORDER BY site_id', [$userId]),
        );
    }

    /**
     * @param list<array{site_id: int, role: string}> $siteRoles
     *
     * @return array<string, mixed>
     */
    public static function toArray(User $user, bool $mfaEnabled, array $siteRoles = []): array
    {
        return [
            'id' => $user->id(),
            'email' => $user->email,
            'display_name' => $user->displayName,
            'global_role' => $user->globalRole->value,
            'locale' => $user->locale,
            'status' => $user->status->value,
            'mfa_enabled' => $mfaEnabled,
            'last_login_at' => $user->lastLoginAt?->format(\DATE_ATOM),
            'created_at' => $user->createdAt->format(\DATE_ATOM),
            'site_roles' => $siteRoles,
        ];
    }
}
