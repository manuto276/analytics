<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Analytics\Conversions\Http\ApiKeyAuthMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API-key authentication for the server-to-server API.
 */
final readonly class ServerApiGroupMiddleware implements MiddlewareInterface
{
    public function __construct(private ApiKeyAuthMiddleware $apiKeys) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return Pipeline::run([$this->apiKeys], $request, $handler);
    }
}
