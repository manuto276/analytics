<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Kernel;

use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\TestContainer;

final class OpsEndpointTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->validateOpenApi = false;
    }

    public function testDisabledWithoutAToken(): void
    {
        $this->assertProblem($this->post('/_ops/opcache-reset'), 404, 'not_found');
    }

    public function testRequiresLoopbackAndTheToken(): void
    {
        $container = TestContainer::build(['OPS_TOKEN' => 'secret-ops-token']);
        $this->container = $container;
        $this->factory = new \Analytics\Tests\Support\Factory($container);

        try {
            $remote = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer secret-ops-token'], ['REMOTE_ADDR' => '203.0.113.5']);
            $this->assertProblem($remote, 403, 'forbidden');

            $noToken = $this->request('POST', '/_ops/opcache-reset', [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $this->assertProblem($noToken, 401, 'unauthorized');

            $wrongToken = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer nope'], ['REMOTE_ADDR' => '127.0.0.1']);
            $this->assertProblem($wrongToken, 401, 'unauthorized');

            $ok = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer secret-ops-token'], ['REMOTE_ADDR' => '127.0.0.1']);
            $this->assertStatus(200, $ok);
            self::assertArrayHasKey('reset', $this->json($ok));
            self::assertSame('no-store', $ok->getHeaderLine('Cache-Control'));
        } finally {
            $container->get(\Doctrine\DBAL\Connection::class)->close();
            $this->container = TestContainer::get();
        }
    }
}
