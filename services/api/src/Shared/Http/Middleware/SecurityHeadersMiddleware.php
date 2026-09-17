<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Two header profiles: tracking endpoints (/t/*, embeddable cross-origin) and dashboard/API.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @param list<string> $scriptHashes CSP hashes of inline scripts in the SPA (sha256-...) */
    public function __construct(
        private bool $https,
        private array $scriptHashes,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if (str_starts_with($path, '/t/')) {
            return $response
                ->withHeader('Cross-Origin-Resource-Policy', 'cross-origin')
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        $scriptSrc = "'self'";
        foreach ($this->scriptHashes as $hash) {
            $scriptSrc .= " '" . $hash . "'";
        }
        $isHtml = str_contains($response->getHeaderLine('Content-Type'), 'text/html');
        $csp = $isHtml
            ? "default-src 'self'; script-src {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
            : "default-src 'none'; frame-ancestors 'none'";

        $response = $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=(), browsing-topics=()');
        if ($this->https) {
            return $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
