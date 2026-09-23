<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Consent\Application\ConsentService;
use Analytics\Sites\Domain\SiteSnapshot;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds "/*! header *\/ window.__an_cfg=…; [<banner>] <tracker>" for a site, cached per
 * configuration hash. The banner module (dist/banner.js) is included only for sites with the cookie
 * level on; it registers itself before the core (dist/tracker.js) runs.
 *
 * A missing file is a broken release, not a normal state: outside development it is logged as an
 * error on every build (and reported by `app:preflight`); the no-op stub keeps pages working.
 */
final readonly class ScriptBundleBuilder
{
    public const string HEADER = '/*! analytics | AGPL-3.0-or-later | source: %s */';
    /** Development fallback before `pnpm build:api` has run: a no-op that keeps the queue stub. */
    public const string STUB = '(function(){var c=window.__an_cfg||{};window[c.g||"analytics"]=window[c.g||"analytics"]||{q:[],track:function(){}};})();';

    private string $bannerPath;

    public function __construct(
        private ConsentService $consent,
        private CacheItemPoolInterface $cache,
        private string $trackerPath,
        private string $sourceUrl,
        ?string $bannerPath = null,
        private ?LoggerInterface $logger = null,
        /** True in production: a missing file is logged as an error. */
        private bool $strict = false,
    ) {
        $this->bannerPath = $bannerPath ?? \dirname($trackerPath) . '/banner.js';
    }

    /** @return array{body: string, etag: string} */
    public function build(SiteSnapshot $site): array
    {
        $config = $this->config($site);
        $tracker = $this->tracker();
        $banner = $config['consent'] !== null ? $this->banner() : ['code' => '', 'sha256' => ''];
        $configJson = json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);
        $etag = '"' . substr(hash('sha256', $configJson . "\0" . $banner['sha256'] . "\0" . $tracker['sha256']), 0, 32) . '"';

        $item = $this->cache->getItem('tracker_bundle_' . sha1($etag));
        if ($item->isHit() && \is_string($item->get())) {
            return ['body' => $item->get(), 'etag' => $etag];
        }
        $body = \sprintf(self::HEADER, $this->sourceUrl) . "\nwindow.__an_cfg=" . $configJson . ";\n"
            . ($banner['code'] === '' ? '' : rtrim($banner['code']) . "\n")
            . $tracker['code'];
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

    /** @return array{code: string, sha256: string} the core, or the no-op stub when it is missing */
    public function tracker(): array
    {
        $code = $this->read($this->trackerPath) ?? self::STUB;

        return ['code' => $code, 'sha256' => hash('sha256', $code)];
    }

    /** @return array{code: string, sha256: string} the banner module, or nothing when it is missing */
    public function banner(): array
    {
        $code = $this->read($this->bannerPath) ?? '';

        return ['code' => $code, 'sha256' => hash('sha256', $code)];
    }

    /**
     * The script files this installation must have, with whether each one is there.
     *
     * @return array<string, bool> path => present
     */
    public function files(): array
    {
        return [$this->trackerPath => is_file($this->trackerPath), $this->bannerPath => is_file($this->bannerPath)];
    }

    private function read(string $path): ?string
    {
        $code = is_file($path) ? file_get_contents($path) : false;
        if ($code === false || trim($code) === '') {
            if ($this->strict) {
                $this->logger?->error('Tracker script file missing or unreadable: {path}. Sites get a no-op stub (or no banner) until it is restored; run app:preflight.', ['path' => $path]);
            }

            return null;
        }

        // Strip the build header: the served bundle has its own.
        return (string) preg_replace('#^/\*!.*?\*/\s*#s', '', $code);
    }
}
