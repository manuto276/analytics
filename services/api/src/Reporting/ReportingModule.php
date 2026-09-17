<?php

declare(strict_types=1);

namespace Analytics\Reporting;

use Analytics\Identity\Application\Permission;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;
use Analytics\Kernel\Settings;
use Analytics\Reporting\Application\QueryPlanner;
use Analytics\Reporting\Application\Reports\CohortsReport;
use Analytics\Reporting\Http\ReportsController;

use function DI\autowire;

final class ReportingModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            QueryPlanner::class => autowire()->constructorParameter('retentionMonths', $settings->retentionMonths),
            CohortsReport::class => autowire()->constructorParameter('retentionMonths', $settings->retentionMonths),
        ];
    }

    public function apiRoutes(SecuredRoutes $routes): void
    {
        $base = '/sites/{siteId:[0-9]+}/reports';
        $view = Permission::SITE_VIEW;
        $routes->get($base . '/overview', [ReportsController::class, 'overview'], $view);
        $routes->get($base . '/timeseries', [ReportsController::class, 'timeseries'], $view);
        $routes->get($base . '/pages', [ReportsController::class, 'pages'], $view);
        $routes->get($base . '/landing-pages', [ReportsController::class, 'landingPages'], $view);
        $routes->get($base . '/sources', [ReportsController::class, 'sources'], $view);
        $routes->get($base . '/campaigns', [ReportsController::class, 'campaigns'], $view);
        $routes->get($base . '/tech', [ReportsController::class, 'tech'], $view);
        $routes->get($base . '/countries', [ReportsController::class, 'countries'], $view);
        $routes->get($base . '/events', [ReportsController::class, 'events'], $view);
        $routes->get($base . '/events/{eventName}/props', [ReportsController::class, 'eventProps'], $view);
        $routes->get($base . '/content', [ReportsController::class, 'content'], $view);
        $routes->get($base . '/realtime', [ReportsController::class, 'realtime'], $view);
        $routes->get($base . '/goals', [ReportsController::class, 'goals'], $view);
        $routes->get($base . '/conversions', [ReportsController::class, 'conversions'], $view);
        $routes->get($base . '/funnels/{funnelId:[0-9]+}', [ReportsController::class, 'funnel'], $view);
        $routes->get($base . '/attribution', [ReportsController::class, 'attribution'], $view);
        $routes->get($base . '/cohorts', [ReportsController::class, 'cohorts'], $view);
        $routes->get($base . '/consent', [ReportsController::class, 'consent'], $view);
    }

    public function commands(): array
    {
        return [
            Console\RollupRunCommand::class,
            Console\RollupRebuildCommand::class,
            Console\JobsStatusCommand::class,
            Console\DevSeedCommand::class,
        ];
    }
}
