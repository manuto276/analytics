<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tracking endpoints never read or set cookies on the service domain.
 */
final class NoCookiesGuardMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withCookieParams([])->withoutHeader('Cookie');

        return $handler->handle($request)->withoutHeader('Set-Cookie');
    }
}
