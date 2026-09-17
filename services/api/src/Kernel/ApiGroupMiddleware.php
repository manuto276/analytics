<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Analytics\Identity\Http\AccessMiddleware;
use Analytics\Identity\Http\CsrfMiddleware;
use Analytics\Identity\Http\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session → CSRF → rate limit, site access and permission, for session-authenticated API routes.
 */
final readonly class ApiGroupMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionMiddleware $session,
        private CsrfMiddleware $csrf,
        private AccessMiddleware $access,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return Pipeline::run([$this->session, $this->csrf, $this->access], $request, $handler);
    }
}
