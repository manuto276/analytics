<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Kernel;

use Analytics\Kernel\Settings;
use Analytics\Tests\Support\HttpTestCase;

final class SpaFallbackTest extends HttpTestCase
{
    private string $indexFile;
    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validateOpenApi = false;
        $this->indexFile = $this->service(Settings::class)->projectDir . '/public/index.html';
        if (!is_file($this->indexFile)) {
            file_put_contents($this->indexFile, '<!doctype html><html lang="en"><head><script>window.x=1</script></head><body>dashboard</body></html>');
            $this->created = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            @unlink($this->indexFile);
        }
        parent::tearDown();
    }

    public function testDeepLinksServeTheDashboardWithSecurityHeaders(): void
    {
        foreach (['/', '/login', '/settings/consent', '/admin/users'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response);
            self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
            self::assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
            self::assertSame('no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
            self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        }

        $etag = $this->get('/login')->getHeaderLine('ETag');
        $this->assertStatus(304, $this->get('/login', ['If-None-Match' => $etag]));
    }

    public function testTheDashboardMayFrameOnlyItsOwnPages(): void
    {
        $csp = $this->get('/settings/consent')->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("frame-src 'self';", $csp);
        self::assertStringContainsString("default-src 'self';", $csp);
    }

    public function testTheBannerPreviewPageHasItsOwnStrictPolicy(): void
    {
        $file = $this->service(Settings::class)->projectDir . '/public/_preview/banner.html';
        $created = false;
        if (!is_file($file)) {
            @mkdir(\dirname($file), 0o777, true);
            file_put_contents($file, '<!doctype html><html><head><script src="banner.js"></script><script src="preview.js"></script></head><body></body></html>');
            $created = true;
        }
        try {
            $response = $this->get('/_preview/banner.html');
            $this->assertStatus(200, $response);
            self::assertSame((string) file_get_contents($file), (string) $response->getBody(), 'the preview page itself, not the SPA shell');
            $csp = $response->getHeaderLine('Content-Security-Policy');
            self::assertStringContainsString("default-src 'none'", $csp);
            self::assertStringContainsString("script-src 'self';", $csp, 'no inline scripts, no hashes');
            self::assertStringContainsString("frame-ancestors 'self'", $csp);
            self::assertStringNotContainsString('connect-src', $csp, 'no network requests (default-src none)');
            self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));

            // Nothing else under /_preview/ is special: other documents are the SPA with frame-ancestors 'none'.
            self::assertSame('DENY', $this->get('/_preview/other.html')->getHeaderLine('X-Frame-Options'));
        } finally {
            if ($created) {
                @unlink($file);
                @rmdir(\dirname($file));
            }
        }
    }

    public function testApiAndTrackerPathsAreNotSwallowed(): void
    {
        $this->assertProblem($this->get('/api/v1/unknown'), 404, 'not_found');
        $this->assertStatus(404, $this->get('/t/pk_' . str_repeat('A', 21) . '.js'));
    }

    public function testRobotsDisallowsIndexing(): void
    {
        $response = $this->get('/robots.txt');
        $this->assertStatus(200, $response);
        self::assertSame("User-agent: *\nDisallow: /\n", (string) $response->getBody());
    }
}
