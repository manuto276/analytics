<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Analytics\Shared\Http\ApiProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing dashboard requests whose Origin (or Sec-Fetch-Site) is not the service itself.
 */
final readonly class SameOriginMiddleware implements MiddlewareInterface
{
    public function __construct(private string $appOrigin) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $fetchSite = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
            if ($fetchSite !== '' && !\in_array($fetchSite, ['same-origin', 'none'], true)) {
                throw ApiProblem::forbidden('Cross-site requests are not allowed.');
            }
            $origin = strtolower($request->getHeaderLine('Origin'));
            if ($origin !== '' && $origin !== 'null' && rtrim($origin, '/') !== $this->appOrigin) {
                throw ApiProblem::forbidden('Cross-origin requests are not allowed.');
            }
            if ($origin === 'null') {
                throw ApiProblem::forbidden('Cross-origin requests are not allowed.');
            }
        }

        return $handler->handle($request);
    }
}
