<?php

declare(strict_types=1);

namespace Analytics\Conversions\Http;

use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Conversions\Domain\ApiKey;
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
 * Bearer API key authentication for /api/v1/server/*: checks the key, the scope declared by the
 * route and that the key belongs to the site in the path.
 */
final readonly class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ApiKeyService $keys,
        private SiteRepository $sites,
        private RateLimiter $rateLimiter,
        private RouteRegistry $routes,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null) {
            throw ApiProblem::notFound();
        }
        $scope = $this->routes->requirementFor($route, $request->getMethod());
        if (!\is_string($scope) || !\in_array($scope, ApiKey::SCOPES, true)) {
            throw new \LogicException(\sprintf('Route %s %s declares no API scope.', implode('|', $route->getMethods()), $route->getPattern()));
        }

        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            throw ApiProblem::unauthorized('Send an API key as "Authorization: Bearer ak_…".');
        }
        $token = trim(substr($header, 7));
        $prefix = preg_match(ApiKeyService::PATTERN, $token, $m) === 1 ? $m[1] : 'unknown';
        $this->rateLimiter->enforce('server', 'key:' . $prefix);

        $key = $this->keys->verify($token);
        if ($key === null) {
            throw ApiProblem::unauthorized('The API key is not valid.');
        }
        if (!$key->hasScope($scope)) {
            throw ApiProblem::forbidden('This API key does not have the "' . $scope . '" scope.');
        }

        $publicKey = $route->getArgument('publicKey');
        $site = \is_string($publicKey) ? $this->sites->findByPublicKey($publicKey) : null;
        if ($site === null || $site->id() !== $key->siteId) {
            throw ApiProblem::notFound('Site not found.');
        }

        return $handler->handle($request
            ->withAttribute(RequestAttributes::API_KEY, $key)
            ->withAttribute(RequestAttributes::SITE, $site));
    }
}
