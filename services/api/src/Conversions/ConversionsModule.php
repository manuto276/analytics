<?php

declare(strict_types=1);

namespace Analytics\Conversions;

use Analytics\Conversions\Http\ConversionsAdminController;
use Analytics\Conversions\Http\ServerController;
use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;

final class ConversionsModule extends Module
{
    public function apiRoutes(SecuredRoutes $routes): void
    {
        $site = '/sites/{siteId:[0-9]+}';
        $manage = Permission::SITE_MANAGE;
        $view = Permission::SITE_VIEW;

        $routes->get($site . '/api-keys', [ConversionsAdminController::class, 'listApiKeys'], $manage);
        $routes->post($site . '/api-keys', [ConversionsAdminController::class, 'createApiKey'], $manage);
        $routes->delete($site . '/api-keys/{keyId:[0-9]+}', [ConversionsAdminController::class, 'revokeApiKey'], $manage);

        $routes->get($site . '/goals', [ConversionsAdminController::class, 'listGoals'], $view);
        $routes->post($site . '/goals', [ConversionsAdminController::class, 'createGoal'], $manage);
        $routes->patch($site . '/goals/{goalId:[0-9]+}', [ConversionsAdminController::class, 'updateGoal'], $manage);
        $routes->delete($site . '/goals/{goalId:[0-9]+}', [ConversionsAdminController::class, 'deleteGoal'], $manage);

        $routes->get($site . '/funnels', [ConversionsAdminController::class, 'listFunnels'], $view);
        $routes->post($site . '/funnels', [ConversionsAdminController::class, 'createFunnel'], $manage);
        $routes->patch($site . '/funnels/{funnelId:[0-9]+}', [ConversionsAdminController::class, 'updateFunnel'], $manage);
        $routes->delete($site . '/funnels/{funnelId:[0-9]+}', [ConversionsAdminController::class, 'deleteFunnel'], $manage);

        $routes->get($site . '/costs', [ConversionsAdminController::class, 'listCosts'], $view);
        $routes->post($site . '/costs', [ConversionsAdminController::class, 'createCost'], $manage);
        $routes->post($site . '/costs/import', [ConversionsAdminController::class, 'importCosts'], $manage);
        $routes->patch($site . '/costs/{costId:[0-9]+}', [ConversionsAdminController::class, 'updateCost'], $manage);
        $routes->delete($site . '/costs/{costId:[0-9]+}', [ConversionsAdminController::class, 'deleteCost'], $manage);
    }

    public function serverRoutes(SecuredRoutes $routes): void
    {
        $routes->post('/sites/{publicKey:pk_[A-Za-z0-9]{21}}/conversions', [ServerController::class, 'conversions'], 'conversions:write');
        $routes->get('/sites/{publicKey:pk_[A-Za-z0-9]{21}}/content/{contentKey}/stats', [ServerController::class, 'contentStats'], 'stats:read');
    }

    public function commands(): array
    {
        return [
            Console\ConversionsReattributeCommand::class,
            Console\ApiKeyCreateCommand::class,
            Console\ApiKeyRevokeCommand::class,
        ];
    }

    public function entityPaths(): array
    {
        return [__DIR__ . '/Domain'];
    }
}
