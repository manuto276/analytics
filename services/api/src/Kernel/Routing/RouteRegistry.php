<?php

declare(strict_types=1);

namespace Analytics\Kernel\Routing;

use Slim\Interfaces\RouteInterface;

/**
 * Declared requirement (permission or API-key scope) per route, keyed by "METHOD pattern".
 */
final class RouteRegistry
{
    /** @var array<string, string> */
    private array $requirements = [];

    public function declare(RouteInterface $route, string $requirement): void
    {
        foreach ($route->getMethods() as $method) {
            $this->requirements[$method . ' ' . $route->getPattern()] = $requirement;
        }
    }

    public function requirementFor(RouteInterface $route, string $method): ?string
    {
        return $this->requirements[$method . ' ' . $route->getPattern()] ?? null;
    }
}
