<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;

use function DI\factory;

final class KernelModule extends Module
{
    public const string BANNER_PREVIEW_PATH = '/_preview/banner.html';

    public function definitions(Settings $settings): array
    {
        return [
            DependencyFactory::class => factory(static fn(Connection $c, Settings $s, EntityManagerInterface $em): DependencyFactory => ConsoleApplicationFactory::migrationsDependencyFactory($c, $s, $em)),
        ];
    }

    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function rootRoutes(RouteCollectorProxyInterface $group): void
    {
        $group->get('/robots.txt', [Http\SpaFallbackAction::class, 'robots']);
        $group->post('/_ops/opcache-reset', [Http\OpsController::class, 'resetOpcache']);
        // The consent banner preview the dashboard frames: its own document and CSP, not the SPA shell.
        $group->get(self::BANNER_PREVIEW_PATH, [Http\SpaFallbackAction::class, 'bannerPreview']);
        // Anything that is not the API or the tracker falls back to the dashboard SPA.
        $group->get('/{path:(?!api/|t/|_ops/).*}', Http\SpaFallbackAction::class);
    }

    public function commands(): array
    {
        return [
            Console\PreflightCommand::class,
            Console\MailTestCommand::class,
            Console\CacheWarmupCommand::class,
            Console\CacheClearCommand::class,
            Console\SecretsGenerateCommand::class,
            Console\OrmValidateSchemaCommand::class,
        ];
    }
}
