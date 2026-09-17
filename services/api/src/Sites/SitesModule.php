<?php

declare(strict_types=1);

namespace Analytics\Sites;

use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;
use Analytics\Kernel\Settings;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Application\SnippetRenderer;
use Analytics\Sites\Http\SitesController;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

use function DI\autowire;
use function DI\get;

final class SitesModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            CacheItemPoolInterface::class => get(AdapterInterface::class),
            SiteRepository::class => autowire(),
            SnippetRenderer::class => autowire()->constructorParameter('appUrl', $settings->appUrl),
        ];
    }

    public function apiRoutes(SecuredRoutes $routes): void
    {
        $routes->get('/sites', [SitesController::class, 'list'], Permission::AUTHENTICATED);
        $routes->post('/sites', [SitesController::class, 'create'], Permission::ADMIN);
        $routes->get('/sites/{siteId:[0-9]+}', [SitesController::class, 'show'], Permission::SITE_VIEW);
        $routes->patch('/sites/{siteId:[0-9]+}', [SitesController::class, 'update'], Permission::SITE_MANAGE);
        $routes->delete('/sites/{siteId:[0-9]+}', [SitesController::class, 'archive'], Permission::SITE_MANAGE);
        $routes->get('/sites/{siteId:[0-9]+}/snippet', [SitesController::class, 'snippet'], Permission::SITE_VIEW);
        $routes->get('/sites/{siteId:[0-9]+}/members', [SitesController::class, 'members'], Permission::SITE_MANAGE);
        $routes->post('/sites/{siteId:[0-9]+}/members', [SitesController::class, 'addMemberByEmail'], Permission::SITE_MANAGE);
        $routes->put('/sites/{siteId:[0-9]+}/members/{userId:[0-9]+}', [SitesController::class, 'setMember'], Permission::SITE_MANAGE);
        $routes->delete('/sites/{siteId:[0-9]+}/members/{userId:[0-9]+}', [SitesController::class, 'removeMember'], Permission::SITE_MANAGE);
    }

    public function commands(): array
    {
        return [
            Console\SiteCreateCommand::class,
            Console\SiteListCommand::class,
            Console\SiteShowCommand::class,
            Console\SiteDomainAddCommand::class,
            Console\SiteDomainRemoveCommand::class,
        ];
    }

    public function entityPaths(): array
    {
        return [__DIR__ . '/Domain'];
    }
}
