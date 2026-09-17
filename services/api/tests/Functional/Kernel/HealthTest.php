<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Kernel;

use Analytics\Tests\Support\HttpTestCase;

final class HealthTest extends HttpTestCase
{
    public function testPublicHealthReturnsStatusAndCommit(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertStatus(200, $response);
        $body = $this->json($response);
        self::assertContains($body['status'], ['ok', 'warn']);
        self::assertArrayHasKey('commit', $body);
        self::assertArrayHasKey('version', $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testDetailedHealthRequiresAdmin(): void
    {
        $this->assertProblem($this->get('/api/v1/admin/health'), 401, 'unauthorized');

        $this->loginAs($this->factory->user());
        $this->assertProblem($this->get('/api/v1/admin/health'), 403, 'forbidden');

        $this->loginAs($this->factory->admin());
        $data = $this->data($this->get('/api/v1/admin/health'));
        self::assertSame('ok', $data['checks']['database']['status']);
        self::assertSame('ok', $data['checks']['migrations']['status']);
    }

    public function testUnknownRouteIsProblemDetails(): void
    {
        $this->validateOpenApi = false;
        $response = $this->get('/api/v1/does-not-exist');
        $this->assertProblem($response, 404, 'not_found');
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
    }
}
