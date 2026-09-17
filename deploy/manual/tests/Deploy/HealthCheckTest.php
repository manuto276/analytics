<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Deploy;

use AnalyticsDeploy\Tests\Support\DeployTestCase;
use AnalyticsDeploy\Tests\Support\HealthServer;
use AnalyticsDeploy\Tests\Support\PackageBuilder;

final class HealthCheckTest extends DeployTestCase
{
    private HealthServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->init();
        $this->server = $this->startHealth();
        $this->writeIni(['health_url' => $this->server->url, 'health_tries' => 3, 'health_interval' => 0.05]);
    }

    public function testHealthyDeploy(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $r = $this->deploy($pkg);
        $this->assertDeployOk($r);
        self::assertStringContainsString('HTTP 200, commit ' . $pkg->commit, $r->stdout);
    }

    public function testUnhealthyDeployIsRolledBack(): void
    {
        $a = PackageBuilder::make(self::ts(1));
        $this->assertDeployOk($this->deploy($a));

        $this->server->override(500, ['status' => 'error']);
        $b = PackageBuilder::make(self::ts(2));
        $r = $this->deploy($b);
        self::assertSame(1, $r->exit, $r->output());
        self::assertStringContainsString('attempt 3/3: HTTP 500', $r->stdout);
        self::assertStringContainsString('current restored to ' . self::ts(1), $r->stdout);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());

        $last = $this->history()[1];
        self::assertSame(['deploy', self::ts(2), 'rolled_back', self::ts(1), $b->commit], [$last['action'], $last['release'], $last['result'], $last['previous'], $last['commit']]);
        self::assertStringContainsString('health check failed', $last['message']);
    }

    public function testWrongCommitCountsAsUnhealthy(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $this->server->override(200, ['status' => 'ok', 'commit' => str_repeat('f', 40)]);
        $r = $this->deploy(PackageBuilder::make(self::ts(2)));
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('commit is "' . str_repeat('f', 40) . '"', $r->stdout);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());
    }

    public function testNoAutoRollbackKeepsNewRelease(): void
    {
        $this->writeIni(['health_url' => $this->server->url, 'health_tries' => 1, 'auto_rollback' => 'false']);
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $this->server->override(503, 'down');
        $r = $this->deploy(PackageBuilder::make(self::ts(2)));
        self::assertSame(1, $r->exit);
        self::assertSame('releases/' . self::ts(2), $this->currentTarget());
        self::assertSame('failed', $this->history()[1]['result']);
    }

    public function testNoHealthSkipsCheck(): void
    {
        $this->server->override(500);
        $r = $this->deploy(PackageBuilder::make(self::ts(1)), ['--no-health']);
        $this->assertDeployOk($r);
        self::assertStringContainsString('Skipping health check (--no-health)', $r->stdout);
    }

    public function testUnreachableHealthUrl(): void
    {
        $this->writeIni(['health_url' => 'http://127.0.0.1:1/api/v1/health', 'health_tries' => 1]);
        $r = $this->deploy(PackageBuilder::make(self::ts(1)));
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('request failed', $r->stdout);
        self::assertStringContainsString('no previous release to roll back to', $r->stdout);
    }

    public function testStatusReportsHealth(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $this->assertDeployOk($this->deploy($pkg));
        $r = $this->console(['status']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('Health:      ok (HTTP 200, commit ' . $pkg->commit . ')', $r->stdout);

        $this->server->override(500);
        $r = $this->console(['status']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('Health:      FAILED (HTTP 500)', $r->stdout);
    }
}
