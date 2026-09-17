<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Identity\Application\PasswordHasher;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Sites\Application\SiteService;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\VisitorHashMode;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Test data builders. Everything is flushed immediately.
 */
final class Factory
{
    public const string PASSWORD = 'correct horse battery staple';

    private static int $sequence = 0;

    public function __construct(private readonly Container $container) {}

    private function em(): EntityManagerInterface
    {
        $em = $this->container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function now(): \DateTimeImmutable
    {
        $clock = $this->container->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);

        return $clock->now();
    }

    public function db(): Connection
    {
        $db = $this->container->get(Connection::class);
        \assert($db instanceof Connection);

        return $db;
    }

    public function user(GlobalRole $role = GlobalRole::Member, ?string $email = null, string $password = self::PASSWORD): User
    {
        $hasher = $this->container->get(PasswordHasher::class);
        \assert($hasher instanceof PasswordHasher);
        $email ??= 'user' . (++self::$sequence) . '-' . bin2hex(random_bytes(3)) . '@example.com';
        $user = new User($email, $hasher->hash($password), 'User ' . self::$sequence, $role, $this->now());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    public function admin(?string $email = null): User
    {
        return $this->user(GlobalRole::Admin, $email);
    }

    /**
     * @param array<string, mixed> $overrides property => value
     * @param list<string>|null    $hosts
     */
    public function site(array $overrides = [], ?array $hosts = null, bool $includeSubdomains = true): Site
    {
        $site = new Site(SiteService::generatePublicKey(), 'Site ' . (++self::$sequence), $this->now());
        foreach ($hosts ?? ['site.test'] as $host) {
            $site->addDomain($host, $includeSubdomains, $this->now());
        }
        $site->visitorHashMode = VisitorHashMode::DailyHash;
        foreach ($overrides as $property => $value) {
            $site->{$property} = $value;
        }
        $this->em()->persist($site);
        $this->em()->flush();

        return $site;
    }

    public function grant(User $user, Site $site, SiteRole $role): void
    {
        $this->db()->insert('user_site_roles', [
            'user_id' => $user->id(),
            'site_id' => $site->id(),
            'role' => $role->value,
            'created_at' => $this->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
