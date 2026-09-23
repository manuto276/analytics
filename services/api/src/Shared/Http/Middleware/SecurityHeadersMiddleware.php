<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Three header profiles: tracking endpoints (/t/*, embeddable cross-origin), the consent banner
 * preview page (framed by the dashboard) and dashboard/API.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * The static page services/dashboard/public/_preview/banner.html. The dashboard frames it in a
     * sandbox without allow-same-origin; it only runs the banner module and preview.js from this
     * origin, loads nothing else and may be framed by the dashboard only.
     */
    private const string BANNER_PREVIEW = '/_preview/banner.html';

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

        if ($path === self::BANNER_PREVIEW) {
            return $this->bannerPreview($response);
        }

        $scriptSrc = "'self'";
        foreach ($this->scriptHashes as $hash) {
            $scriptSrc .= " '" . $hash . "'";
        }
        $isHtml = str_contains($response->getHeaderLine('Content-Type'), 'text/html');
        $csp = $isHtml
            ? "default-src 'self'; script-src {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
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

    private function bannerPreview(ResponseInterface $response): ResponseInterface
    {
        $response = $response
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src data:; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=(), browsing-topics=()');

        return $this->https ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains') : $response;
    }
}
