<?php

declare(strict_types=1);

namespace Analytics\Consent;

use Analytics\Consent\Http\ConsentController;
use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;

final class ConsentModule extends Module
{
    public function apiRoutes(SecuredRoutes $routes): void
    {
        $site = '/sites/{siteId:[0-9]+}/consent';
        $routes->get($site, [ConsentController::class, 'show'], Permission::SITE_VIEW);
        $routes->put($site . '/draft', [ConsentController::class, 'saveDraft'], Permission::SITE_MANAGE);
        $routes->delete($site . '/draft', [ConsentController::class, 'discardDraft'], Permission::SITE_MANAGE);
        $routes->post($site . '/publish', [ConsentController::class, 'publish'], Permission::SITE_MANAGE);
        $routes->get($site . '/history', [ConsentController::class, 'history'], Permission::SITE_VIEW);
        $routes->get($site . '/receipts', [ConsentController::class, 'receipts'], Permission::SITE_MANAGE);
    }

    public function entityPaths(): array
    {
        return [__DIR__ . '/Domain'];
    }
}
