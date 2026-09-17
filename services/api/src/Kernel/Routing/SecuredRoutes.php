<?php

declare(strict_types=1);

namespace Analytics\Kernel\Routing;

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteInterface;

/**
 * Route builder that forces every route to declare its permission (session API) or scope (server API).
 */
final readonly class SecuredRoutes
{
    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function __construct(
        private RouteCollectorProxyInterface $group,
        private RouteRegistry $registry,
    ) {}

    /** @param callable|array{class-string, string}|string $handler */
    public function get(string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        return $this->add(['GET'], $pattern, $handler, $requirement);
    }

    /** @param callable|array{class-string, string}|string $handler */
    public function post(string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        return $this->add(['POST'], $pattern, $handler, $requirement);
    }

    /** @param callable|array{class-string, string}|string $handler */
    public function put(string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        return $this->add(['PUT'], $pattern, $handler, $requirement);
    }

    /** @param callable|array{class-string, string}|string $handler */
    public function patch(string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        return $this->add(['PATCH'], $pattern, $handler, $requirement);
    }

    /** @param callable|array{class-string, string}|string $handler */
    public function delete(string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        return $this->add(['DELETE'], $pattern, $handler, $requirement);
    }

    /**
     * @param list<string>                                $methods
     * @param callable|array{class-string, string}|string $handler
     */
    private function add(array $methods, string $pattern, callable|array|string $handler, string $requirement): RouteInterface
    {
        $route = $this->group->map($methods, $pattern, $handler);
        $this->registry->declare($route, $requirement);

        return $route;
    }
}
