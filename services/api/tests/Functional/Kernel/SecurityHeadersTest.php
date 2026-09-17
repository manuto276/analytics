<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Kernel;

use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class SecurityHeadersTest extends HttpTestCase
{
    public function testDashboardAndApiResponsesAreLockedDown(): void
    {
        $this->loginAs($this->factory->admin());
        $response = $this->get('/api/v1/auth/me');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString('camera=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $response->getHeaderLine('X-Request-Id'));
    }

    public function testTrackingResponsesUseTheEmbeddableProfile(): void
    {
        $site = $this->factory->site([], ['www.site.test']);
        foreach ([
            $this->get('/t/' . $site->publicKey . '.js'),
            $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview()])),
        ] as $response) {
            self::assertSame('cross-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('', $response->getHeaderLine('Content-Security-Policy'), 'the tracker must be embeddable');
            self::assertFalse($response->hasHeader('Set-Cookie'));
        }
    }

    public function testRequestIdIsEchoedBackWhenProvided(): void
    {
        $response = $this->get('/api/v1/health', ['X-Request-Id' => 'trace-123456']);
        self::assertSame('trace-123456', $response->getHeaderLine('X-Request-Id'));
        $rejected = $this->get('/api/v1/health', ['X-Request-Id' => 'no spaces allowed']);
        self::assertNotSame('no spaces allowed', $rejected->getHeaderLine('X-Request-Id'));
    }

    public function testErrorsAlsoCarrySecurityHeaders(): void
    {
        $response = $this->get('/api/v1/auth/me');
        $this->assertProblem($response, 401);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }
}
