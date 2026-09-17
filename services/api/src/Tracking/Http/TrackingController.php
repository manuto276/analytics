<?php

declare(strict_types=1);

namespace Analytics\Tracking\Http;

use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Crypto\Base64Url;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\RateLimit\RateLimiter;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Tracking\Application\CollectContext;
use Analytics\Tracking\Application\CollectService;
use Analytics\Tracking\Application\ForgetService;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TrackingController
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private SiteRepository $sites,
        private CollectService $collect,
        private ForgetService $forget,
        private ScriptBundleBuilder $scripts,
        private RateLimiter $rateLimiter,
    ) {}

    public function script(ServerRequestInterface $request, string $publicKey): ResponseInterface
    {
        $this->rateLimiter->enforce('script', RequestContext::ipPrefixString($request) ?? '-');
        $site = $this->sites->snapshotByPublicKey($publicKey);
        if ($site === null || $site->archived) {
            $response = $this->responses->createResponse(404)->withHeader('Content-Type', 'application/javascript; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=60');
            $response->getBody()->write('/* analytics: unknown site key */');

            return $response;
        }
        $bundle = $this->scripts->build($site);
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->withHeader('ETag', $bundle['etag'])
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Vary', 'Accept-Encoding');
        $ifNoneMatch = array_map('trim', explode(',', $request->getHeaderLine('If-None-Match')));
        if (\in_array($bundle['etag'], $ifNoneMatch, true) || \in_array('W/' . $bundle['etag'], $ifNoneMatch, true)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($bundle['body']);

        return $response;
    }

    public function collect(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            throw ApiProblem::badRequest('invalid_json', 'Expected a JSON payload.');
        }
        $this->collect->collect($body, $this->context($request));

        return $this->cors($request, $this->responses->createResponse(202)->withHeader('Cache-Control', 'no-store'));
    }

    public function preflight(ServerRequestInterface $request): ResponseInterface
    {
        return $this->cors($request, $this->responses->createResponse(204)
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Cache-Control', 'no-store'));
    }

    public function forget(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $key = \is_array($body) && \is_string($body['k'] ?? null) ? $body['k'] : '';
        $vid = \is_array($body) && \is_string($body['vid'] ?? null) && preg_match('/^[A-Za-z0-9_-]{22}$/', $body['vid']) === 1 ? Base64Url::decode($body['vid']) : null;
        if ($vid === null || \strlen($vid) !== 16) {
            throw ApiProblem::badRequest('invalid_payload', 'Expected {"k": "pk_…", "vid": "…"}.');
        }
        $site = $this->sites->snapshotByPublicKey($key);
        if ($site === null) {
            throw new ApiProblem(404, 'unknown_site', 'Not found', 'Unknown site key.');
        }
        $context = $this->context($request);
        if (!$this->collect->originAllowed($site, $context)) {
            throw new ApiProblem(403, 'origin_not_allowed', 'Forbidden', 'This origin is not allowed for this site.');
        }
        $this->rateLimiter->enforce('public', 'forget:' . $site->id . ':' . (RequestContext::ipPrefixString($request) ?? '-'));
        $this->forget->forget($site, $vid);

        return $this->cors($request, $this->responses->createResponse(202)->withHeader('Cache-Control', 'no-store'));
    }

    private function context(ServerRequestInterface $request): CollectContext
    {
        return new CollectContext(
            ipPrefix: RequestContext::ipPrefix($request),
            userAgent: mb_substr($request->getHeaderLine('User-Agent'), 0, 1024),
            acceptLanguage: $request->getHeaderLine('Accept-Language'),
            origin: $request->getHeaderLine('Origin'),
            referer: $request->getHeaderLine('Referer'),
            doNotTrack: $request->getHeaderLine('DNT') === '1',
            globalPrivacyControl: $request->getHeaderLine('Sec-GPC') === '1',
        );
    }

    private function cors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null' && preg_match('#^https?://[^/]+$#', $origin) === 1) {
            return $response->withHeader('Access-Control-Allow-Origin', $origin)->withHeader('Vary', 'Origin');
        }

        return $response;
    }
}
