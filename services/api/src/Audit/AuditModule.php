<?php

declare(strict_types=1);

namespace Analytics\Audit;

use Analytics\Audit\Http\AuditController;
use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;

final class AuditModule extends Module
{
    public function apiRoutes(SecuredRoutes $routes): void
    {
        $routes->get('/admin/audit-log', [AuditController::class, 'log'], Permission::ADMIN);
        $routes->get('/admin/jobs', [AuditController::class, 'jobs'], Permission::ADMIN);
    }

    public function entityPaths(): array
    {
        return [__DIR__ . '/Domain'];
    }
}
