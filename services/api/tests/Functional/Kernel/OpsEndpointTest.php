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

            // Behind a proxy REMOTE_ADDR is the proxy: a forwarded request is not a local one, and
            // without TRUSTED_PROXIES the chain cannot be checked, so it is refused either way.
            foreach (['X-Forwarded-For' => '203.0.113.5', 'X-Real-IP' => '203.0.113.5', 'Forwarded' => 'for=203.0.113.5'] as $header => $value) {
                $forwarded = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer secret-ops-token', $header => $value], ['REMOTE_ADDR' => '127.0.0.1']);
                $this->assertProblem($forwarded, 403, 'forbidden');
            }
        } finally {
            $container->get(\Doctrine\DBAL\Connection::class)->close();
            $this->container = TestContainer::get();
        }
    }

    public function testAForwardedRequestIsAcceptedWhenTheProxyIsTrusted(): void
    {
        $container = TestContainer::build(['OPS_TOKEN' => 'secret-ops-token', 'TRUSTED_PROXIES' => '127.0.0.1']);
        $this->container = $container;
        $this->factory = new \Analytics\Tests\Support\Factory($container);

        try {
            // The chain is now verifiable: a forwarded remote client still fails the loopback check…
            $remote = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer secret-ops-token', 'X-Forwarded-For' => '203.0.113.5'], ['REMOTE_ADDR' => '127.0.0.1']);
            $this->assertProblem($remote, 403, 'forbidden');

            // …and a local client behind the trusted proxy passes it.
            $local = $this->request('POST', '/_ops/opcache-reset', [], ['Authorization' => 'Bearer secret-ops-token', 'X-Forwarded-For' => '127.0.0.1'], ['REMOTE_ADDR' => '127.0.0.1']);
            $this->assertStatus(200, $local);
        } finally {
            $container->get(\Doctrine\DBAL\Connection::class)->close();
            $this->container = TestContainer::get();
        }
    }
}
