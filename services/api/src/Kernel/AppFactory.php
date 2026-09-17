<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Analytics\Kernel\Routing\RouteRegistry;
use Analytics\Kernel\Routing\SecuredRoutes;
use Analytics\Shared\Http\Middleware\BodyParsingMiddleware;
use Analytics\Shared\Http\Middleware\ClientIpMiddleware;
use Analytics\Shared\Http\Middleware\NoCookiesGuardMiddleware;
use Analytics\Shared\Http\Middleware\RequestIdMiddleware;
use Analytics\Shared\Http\Middleware\SameOriginMiddleware;
use Analytics\Shared\Http\Middleware\SecurityHeadersMiddleware;
use Analytics\Shared\Http\ProblemDetailsErrorHandler;
use DI\Bridge\Slim\Bridge;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface;

final class AppFactory
{
    /** @return App<ContainerInterface> */
    public static function create(ContainerInterface $container): App
    {
        $app = Bridge::create($container);
        $settings = $container->get(Settings::class);
        \assert($settings instanceof Settings);

        $modules = Modules::all();

        $app->group('/t', static function (RouteCollectorProxyInterface $group) use ($modules): void {
            foreach ($modules as $module) {
                $module->trackingRoutes($group);
            }
        })->add(NoCookiesGuardMiddleware::class);

        $registry = $container->get(RouteRegistry::class);
        \assert($registry instanceof RouteRegistry);

        $app->group('/api/v1/server', static function (RouteCollectorProxyInterface $group) use ($modules, $registry): void {
            $routes = new SecuredRoutes($group, $registry);
            foreach ($modules as $module) {
                $module->serverRoutes($routes);
            }
        })->add(ServerApiGroupMiddleware::class);

        $app->group('/api/v1', static function (RouteCollectorProxyInterface $group) use ($modules): void {
            foreach ($modules as $module) {
                $module->publicApiRoutes($group);
            }
        })->add(SameOriginMiddleware::class);

        $app->group('/api/v1', static function (RouteCollectorProxyInterface $group) use ($modules, $registry): void {
            $routes = new SecuredRoutes($group, $registry);
            foreach ($modules as $module) {
                $module->apiRoutes($routes);
            }
        })->add(ApiGroupMiddleware::class)->add(SameOriginMiddleware::class);

        foreach ($modules as $module) {
            $module->rootRoutes($app);
        }

        // Routing runs innermost; middleware added later runs earlier.
        // Effective order (outer → inner): ClientIp → RequestId → SecurityHeaders → Error → BodyParsing → Routing.
        $app->addRoutingMiddleware();
        $app->add(BodyParsingMiddleware::class);
        $errorMiddleware = $app->addErrorMiddleware(!$settings->isProd(), false, false);
        $handler = $container->get(ProblemDetailsErrorHandler::class);
        \assert($handler instanceof ProblemDetailsErrorHandler);
        $errorMiddleware->setDefaultErrorHandler($handler);
        $app->add(SecurityHeadersMiddleware::class);
        $app->add(RequestIdMiddleware::class);
        $app->add(ClientIpMiddleware::class);

        return $app;
    }
}
