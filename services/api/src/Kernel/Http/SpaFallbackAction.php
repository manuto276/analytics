<?php

declare(strict_types=1);

namespace Analytics\Kernel\Http;

use Analytics\Kernel\Settings;
use Analytics\Shared\Http\ApiProblem;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the dashboard SPA for any unmatched GET path, so deep links work and the document
 * carries the application's security headers (nginx can serve index.html directly instead,
 * at the cost of losing the CSP on the document).
 */
final readonly class SpaFallbackAction
{
    public function __construct(private ResponseFactoryInterface $responses, private Settings $settings) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $file = $this->settings->projectDir . '/public/index.html';
        if (!is_file($file)) {
            throw ApiProblem::notFound('The dashboard has not been built. Run `pnpm generate:api` in services/dashboard.');
        }
        $html = (string) file_get_contents($file);
        $etag = '"' . substr(hash('xxh128', $html), 0, 24) . '"';
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate')
            ->withHeader('ETag', $etag);
        if (\in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($html);

        return $response;
    }

    public function robots(): ResponseInterface
    {
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=86400');
        $response->getBody()->write("User-agent: *\nDisallow: /\n");

        return $response;
    }
}
