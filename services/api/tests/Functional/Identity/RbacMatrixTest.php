<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Identity;

use Analytics\Identity\Application\Permission;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Kernel\Modules;
use Analytics\Kernel\Routing\RouteRegistry;
use Analytics\Kernel\Routing\SecuredRoutes;
use Analytics\Tests\Support\HttpTestCase;
use DI\Bridge\Slim\Bridge;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteInterface;

/**
 * Every session-authenticated route × every role. A new route without a declared permission fails here.
 */
final class RbacMatrixTest extends HttpTestCase
{
    private const array ROLES = ['anonymous', 'global_admin', 'site_admin', 'site_viewer', 'no_access'];

    private RouteRegistry $registry;

    /** @return list<RouteInterface> */
    private function sessionRoutes(): array
    {
        $app = Bridge::create($this->container);
        $registry = $this->registry = new RouteRegistry();
        $app->group('/api/v1', static function (RouteCollectorProxyInterface $group) use ($registry): void {
            $routes = new SecuredRoutes($group, $registry);
            foreach (Modules::all() as $module) {
                $module->apiRoutes($routes);
            }
        });

        return array_values($app->getRouteCollector()->getRoutes());
    }

    public function testEverySessionRouteDeclaresAKnownPermission(): void
    {
        $routes = $this->sessionRoutes();
        self::assertGreaterThan(20, \count($routes));
        foreach ($routes as $route) {
            $permission = $this->registry->requirementFor($route, $route->getMethods()[0]);
            self::assertContains($permission, Permission::ALL, \sprintf('%s %s has no valid permission argument', implode('|', $route->getMethods()), $route->getPattern()));
            if (\in_array($permission, [Permission::SITE_VIEW, Permission::SITE_MANAGE], true)) {
                self::assertStringContainsString('{siteId', $route->getPattern(), $route->getPattern() . ' uses a site permission without {siteId}');
            }
        }
    }

    public function testEverySessionRouteIsDocumentedInOpenApi(): void
    {
        $spec = (string) file_get_contents(\dirname(__DIR__, 5) . '/docs/api/openapi.yaml');
        foreach ($this->sessionRoutes() as $route) {
            $path = (string) preg_replace('/\{(\w+):(?:[^{}]|\{[^}]*\})+\}/', '{$1}', substr($route->getPattern(), \strlen('/api/v1')));
            self::assertStringContainsString("\n  " . $path . ":\n", $spec, 'Undocumented route ' . $path);
        }
    }

    public function testMatrix(): void
    {
        $this->validateOpenApi = false;
        $site = $this->factory->site();
        $otherUserForTargets = $this->factory->user();
        $users = [
            'global_admin' => $this->factory->admin(),
            'site_admin' => $this->factory->user(),
            'site_viewer' => $this->factory->user(),
            'no_access' => $this->factory->user(),
        ];
        $this->factory->grant($users['site_admin'], $site, SiteRole::Admin);
        $this->factory->grant($users['site_viewer'], $site, SiteRole::Viewer);
        $this->factory->grant($otherUserForTargets, $site, SiteRole::Viewer);

        $failures = [];
        foreach ($this->sessionRoutes() as $route) {
            $permission = (string) $this->registry->requirementFor($route, $route->getMethods()[0]);
            $uri = (string) preg_replace(
                ['/\{siteId(:(?:[^{}]|\{[^}]*\})+)?\}/', '/\{userId(:(?:[^{}]|\{[^}]*\})+)?\}/', '/\{sessionId(:(?:[^{}]|\{[^}]*\})+)?\}/', '/\{eventName(:(?:[^{}]|\{[^}]*\})+)?\}/', '/\{contentKey(:(?:[^{}]|\{[^}]*\})+)?\}/', '/\{\w+(:(?:[^{}]|\{[^}]*\})+)?\}/'],
                [(string) $site->id(), (string) $otherUserForTargets->id(), '0123456789abcdef', 'signup', 'author:1', '999999'],
                $route->getPattern(),
            );
            foreach ($route->getMethods() as $method) {
                foreach (self::ROLES as $role) {
                    if ($role === 'anonymous') {
                        $this->logout();
                    } else {
                        $this->loginAs($users[$role]);
                    }
                    $response = $this->request($method, $uri, \in_array($method, ['GET', 'HEAD', 'DELETE'], true) ? null : []);
                    $expected = self::expected($permission, $role);
                    $response->getBody()->rewind();
                    $decoded = json_decode((string) $response->getBody(), true);
                    $actual = self::classify($response, \is_array($decoded) ? $decoded : []);
                    if ($expected !== $actual) {
                        $failures[] = \sprintf('%s %s as %s: expected %s, got %s (%d)', $method, $uri, $role, $expected, $actual, $response->getStatusCode());
                    }
                }
            }
        }

        self::assertSame([], $failures);
    }

    private static function expected(string $permission, string $role): string
    {
        if ($role === 'anonymous') {
            return 'unauthenticated';
        }

        return match ($permission) {
            Permission::AUTHENTICATED => 'allowed',
            Permission::ADMIN => $role === 'global_admin' ? 'allowed' : 'forbidden',
            Permission::SITE_VIEW => $role === 'no_access' ? 'hidden' : 'allowed',
            Permission::SITE_MANAGE => match ($role) {
                'no_access' => 'hidden',
                'site_viewer' => 'forbidden',
                default => 'allowed',
            },
            default => 'unknown',
        };
    }

    /** @param array<array-key, mixed> $body */
    private static function classify(ResponseInterface $response, array $body): string
    {
        $status = $response->getStatusCode();

        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 && ($body['code'] ?? '') === 'forbidden' => 'forbidden',
            $status === 404 && ($body['detail'] ?? '') === 'Site not found.' => 'hidden',
            $status >= 500 => 'error:' . ($body['detail'] ?? ''),
            default => 'allowed',
        };
    }
}
