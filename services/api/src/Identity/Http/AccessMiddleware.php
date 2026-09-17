<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Identity\Application\Authorizer;
use Analytics\Identity\Application\Permission;
use Analytics\Identity\Domain\User;
use Analytics\Kernel\Routing\RouteRegistry;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\RequestAttributes;
use Analytics\Shared\RateLimit\RateLimiter;
use Analytics\Sites\Application\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Dashboard rate limit, {siteId} membership and the route's declared permission (fail closed).
 */
final readonly class AccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Authorizer $authorizer,
        private SiteRepository $sites,
        private RateLimiter $rateLimiter,
        private RouteRegistry $routes,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(RequestAttributes::USER);
        \assert($user instanceof User);
        $this->rateLimiter->enforce('dashboard', 'user:' . $user->id());

        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null) {
            throw ApiProblem::notFound();
        }
        $permission = $this->routes->requirementFor($route, $request->getMethod());
        if (!\is_string($permission) || !\in_array($permission, Permission::ALL, true)) {
            throw new \LogicException(\sprintf('Route %s %s declares no valid permission.', implode('|', $route->getMethods()), $route->getPattern()));
        }

        $siteRole = null;
        $siteId = $route->getArgument('siteId');
        if ($siteId !== null) {
            if (!ctype_digit($siteId)) {
                throw ApiProblem::notFound('Site not found.');
            }
            $site = $this->sites->find((int) $siteId);
            $siteRole = $site === null ? null : $this->authorizer->siteRole($user, $site->id());
            if ($site === null || $siteRole === null) {
                // Same response for missing and inaccessible sites.
                throw ApiProblem::notFound('Site not found.');
            }
            $request = $request->withAttribute(RequestAttributes::SITE, $site)->withAttribute(RequestAttributes::SITE_ROLE, $siteRole);
        } elseif (\in_array($permission, [Permission::SITE_VIEW, Permission::SITE_MANAGE], true)) {
            throw new \LogicException('Site permissions require a {siteId} route parameter.');
        }

        if (!Authorizer::allows($permission, $user, $siteRole)) {
            throw ApiProblem::forbidden();
        }

        return $handler->handle($request);
    }
}
