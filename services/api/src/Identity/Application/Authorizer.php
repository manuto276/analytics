<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;

final readonly class Authorizer
{
    public function __construct(private Connection $connection) {}

    public function siteRole(User $user, int $siteId): ?SiteRole
    {
        if ($user->globalRole === GlobalRole::Admin) {
            return SiteRole::Admin;
        }
        $role = $this->connection->fetchOne('SELECT role FROM user_site_roles WHERE user_id = ? AND site_id = ?', [$user->id(), $siteId]);

        return \is_string($role) ? SiteRole::tryFrom($role) : null;
    }

    public static function allows(string $permission, User $user, ?SiteRole $siteRole): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        return match ($permission) {
            Permission::AUTHENTICATED => true,
            Permission::ADMIN => $user->globalRole === GlobalRole::Admin,
            Permission::SITE_VIEW => $siteRole !== null,
            Permission::SITE_MANAGE => $siteRole === SiteRole::Admin,
            default => false,
        };
    }

    /** @return list<int>|null null = all sites */
    public function accessibleSiteIds(User $user): ?array
    {
        if ($user->globalRole === GlobalRole::Admin) {
            return null;
        }

        return array_map(Types::int(...), $this->connection->fetchFirstColumn('SELECT site_id FROM user_site_roles WHERE user_id = ?', [$user->id()]));
    }
}
