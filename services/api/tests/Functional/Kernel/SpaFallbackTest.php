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
