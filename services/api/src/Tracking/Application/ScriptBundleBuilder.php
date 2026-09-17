<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Consent\Application\ConsentService;
use Analytics\Sites\Application\SiteSnapshot;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds "/*! header *\/ window.__an_cfg=…; <tracker>" for a site, cached per configuration hash.
 */
final readonly class ScriptBundleBuilder
{
    public const string HEADER = '/*! analytics | AGPL-3.0-or-later | source: %s */';

    public function __construct(
        private ConsentService $consent,
        private CacheItemPoolInterface $cache,
        private string $trackerPath,
        private string $sourceUrl,
    ) {}

    /** @return array{body: string, etag: string} */
    public function build(SiteSnapshot $site): array
    {
        $config = $this->config($site);
        $tracker = $this->tracker();
        $configJson = json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);
        $etag = '"' . substr(hash('sha256', $configJson . "\0" . $tracker['sha256']), 0, 32) . '"';

        $item = $this->cache->getItem('tracker_bundle_' . sha1($etag));
        if ($item->isHit() && \is_string($item->get())) {
            return ['body' => $item->get(), 'etag' => $etag];
        }
        $body = \sprintf(self::HEADER, $this->sourceUrl) . "\nwindow.__an_cfg=" . $configJson . ";\n" . $tracker['code'];
        $item->set($body)->expiresAfter(3600);
        $this->cache->save($item);

        return ['body' => $body, 'etag' => $etag];
    }

    /** @return array<string, mixed> window.__an_cfg (docs/architecture/tracker.md) */
    public function config(SiteSnapshot $site): array
    {
        return [
            'k' => $site->publicKey,
            'g' => $site->trackerGlobal,
            'b' => $site->baseTrackingEnabled,
            'c' => $site->cookieLevelEnabled,
            'cd' => $site->cookieDomain === null ? null : '.' . ltrim($site->cookieDomain, '.'),
            'vd' => $site->visitorCookieDays,
            'hr' => $site->hashRouting,
            'dnt' => $site->dntMode->value,
            'gpc' => $site->respectGpc,
            'xp' => $site->excludedPaths,
            'loc' => $site->allowLocalhost,
            'ep' => null,
            'auto' => [
                'outbound' => $site->autoEvents['outbound'] ?? false,
                'downloads' => $site->autoEvents['downloads'] ?? false,
                'forms' => $site->autoEvents['forms'] ?? false,
            ],
            'consent' => $site->cookieLevelEnabled ? $this->consent->trackerConfig($site->id) : null,
        ];
    }

    /** @return array{code: string, sha256: string} */
    public function tracker(): array
    {
        if (!is_file($this->trackerPath)) {
            // Development fallback before `pnpm build:api` has run: a no-op that keeps the queue stub.
            $code = '(function(){var c=window.__an_cfg||{};window[c.g||"analytics"]=window[c.g||"analytics"]||{q:[],track:function(){}};})();';

            return ['code' => $code, 'sha256' => hash('sha256', $code)];
        }
        $code = (string) file_get_contents($this->trackerPath);
        // Strip the build header: the served bundle has its own.
        $code = (string) preg_replace('#^/\*!.*?\*/\s*#s', '', $code);

        return ['code' => $code, 'sha256' => hash('sha256', $code)];
    }
}
