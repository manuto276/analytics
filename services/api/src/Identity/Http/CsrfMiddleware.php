<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Identity\Domain\AuthSession;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\RequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-CSRF-Token';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $session = $request->getAttribute(RequestAttributes::AUTH_SESSION);
            $token = $request->getHeaderLine(self::HEADER);
            if (!$session instanceof AuthSession || $token === '' || !hash_equals($session->csrfSecret, $token)) {
                throw new ApiProblem(403, 'csrf_token_invalid', 'Forbidden', 'Missing or invalid CSRF token.');
            }
        }

        return $handler->handle($request);
    }
}
