<?php

declare(strict_types=1);

namespace Analytics\Health;

use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;
use Slim\Interfaces\RouteCollectorProxyInterface;

final class HealthModule extends Module
{
    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function publicApiRoutes(RouteCollectorProxyInterface $group): void
    {
        $group->get('/health', [HealthController::class, 'public']);
    }

    public function apiRoutes(SecuredRoutes $routes): void
    {
        $routes->get('/admin/health', [HealthController::class, 'detailed'], Permission::ADMIN);
    }

    public function commands(): array
    {
        return [HealthCheckCommand::class];
    }
}
