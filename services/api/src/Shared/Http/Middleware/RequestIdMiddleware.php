<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Analytics\Shared\Http\RequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : bin2hex(random_bytes(8));

        return $handler->handle($request->withAttribute(RequestAttributes::REQUEST_ID, $id))->withHeader('X-Request-Id', $id);
    }
}
