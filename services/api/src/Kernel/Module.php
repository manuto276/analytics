<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Analytics\Kernel\Routing\SecuredRoutes;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Symfony\Component\Console\Command\Command;

/**
 * A backend module contributes container definitions, routes (per zone) and console commands.
 * Route zones map to middleware groups (see AppFactory).
 */
abstract class Module
{
    /** @return array<string, mixed> PHP-DI definitions */
    public function definitions(Settings $settings): array
    {
        return [];
    }

    /** Routes under /t (tracking profile, no cookies). */
    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function trackingRoutes(RouteCollectorProxyInterface $group): void {}

    /** Routes under /api/v1/auth and other unauthenticated dashboard routes. */
    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function publicApiRoutes(RouteCollectorProxyInterface $group): void {}

    /** Session-authenticated routes under /api/v1; each declares a permission. */
    public function apiRoutes(SecuredRoutes $routes): void {}

    /** API-key authenticated routes under /api/v1/server; each declares a scope. */
    public function serverRoutes(SecuredRoutes $routes): void {}

    /** Other root-level routes (ops endpoints). */
    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function rootRoutes(RouteCollectorProxyInterface $group): void {}

    /** @return list<class-string<Command>> */
    public function commands(): array
    {
        return [];
    }

    /** @return list<string> directories with Doctrine ORM attribute-mapped entities */
    public function entityPaths(): array
    {
        return [];
    }
}
