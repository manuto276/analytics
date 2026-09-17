<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Deploy;

use AnalyticsDeploy\Tests\Support\DeployTestCase;
use AnalyticsDeploy\Tests\Support\PackageBuilder;

final class RollbackCleanupTest extends DeployTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->init();
    }

    public function testRollbackToPreviousOnlyMovesSymlink(): void
    {
        $a = PackageBuilder::make(self::ts(1));
        $b = PackageBuilder::make(self::ts(2));
        $this->assertDeployOk($this->deploy($a));
        $this->assertDeployOk($this->deploy($b));
        $calls = \count($this->appCalls());

        $r = $this->console(['rollback']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());
        self::assertSame([self::ts(1), self::ts(2)], $this->releaseDirs());
        self::assertCount($calls, $this->appCalls(), 'rollback runs no app commands');
        $last = $this->history()[2];
        self::assertSame(['rollback', self::ts(1), $a->commit, self::ts(2), 'success'], [$last['action'], $last['release'], $last['commit'], $last['previous'], $last['result']]);

        // previous is now the release we rolled back from, so rollback toggles back
        $r = $this->console(['rollback']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertSame('releases/' . self::ts(2), $this->currentTarget());
    }

    public function testRollbackToExplicitRelease(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts($n))));
        }
        self::assertSame(0, $this->console(['rollback', self::ts(1)])->exit);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());

        $r = $this->console(['rollback', self::ts(9)]);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('does not exist', $r->stderr);
        self::assertSame(2, $this->console(['rollback', 'bogus'])->exit);
        self::assertSame(1, $this->console(['rollback', self::ts(1)])->exit, 'already current');
    }

    public function testRollbackWithoutPrevious(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $r = $this->console(['rollback']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('No previous release', $r->stderr);
    }

    public function testRollbackWithUnknownMigrationsRequiresForce(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))->migrations('Version1')));
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(2))->migrations('Version1', 'Version2', 'Version3')));

        $r = $this->console(['rollback']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('migrations unknown to ' . self::ts(1) . ': Version2, Version3', $r->stderr);
        self::assertStringContainsString('--force', $r->stderr);
        self::assertSame('releases/' . self::ts(2), $this->currentTarget());

        $r = $this->console(['rollback', '--force']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('WARNING', $r->stderr);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());
    }

    public function testDeployCleanupKeepsNewestAndNeverCurrentOrPrevious(): void
    {
        $this->writeIni(['keep_releases' => 2, 'keep_packages' => 2]);
        foreach ([1, 2, 3, 4] as $n) {
            $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts($n))));
        }
        self::assertSame([self::ts(3), self::ts(4)], $this->releaseDirs());
        self::assertSame(['analytics-' . self::ts(3) . '.tar.gz', 'analytics-' . self::ts(4) . '.tar.gz'], array_map('basename', glob($this->root . '/packages/*.tar.gz')));
        self::assertFileDoesNotExist($this->root . '/packages/analytics-' . self::ts(1) . '.tar.gz.sha256');
        self::assertFileExists($this->root . '/shared/.env', 'shared data survives release removal');
        self::assertDirectoryExists($this->root . '/shared/var/storage/backups');
    }

    public function testCleanupCommandProtectsCurrentAndPrevious(): void
    {
        $this->writeIni(['keep_packages' => 10]);
        foreach ([1, 2, 3, 4] as $n) {
            $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts($n))));
        }
        file_put_contents($this->root . '/shared/var/log/app.log', 'keep me');
        // current = 1 (old), previous = 4 (rolled back from)
        self::assertSame(0, $this->console(['rollback', self::ts(1)])->exit);
        mkdir($this->root . '/releases/.tmp-' . self::ts(9));

        $r = $this->console(['cleanup', '--keep=1']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertSame([self::ts(1), self::ts(4)], $this->releaseDirs());
        self::assertStringContainsString('removed releases/' . self::ts(2), $r->stdout);
        self::assertSame('keep me', file_get_contents($this->root . '/shared/var/log/app.log'));
        self::assertCount(4, glob($this->root . '/packages/*.tar.gz'), 'packages untouched without --packages');

        $this->writeIni(['keep_packages' => 1]);
        $r = $this->console(['cleanup', '--packages']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertSame(['analytics-' . self::ts(1) . '.tar.gz', 'analytics-' . self::ts(4) . '.tar.gz'], array_map('basename', glob($this->root . '/packages/*.tar.gz')));
        self::assertFileExists($this->root . '/packages/analytics-' . self::ts(1) . '.tar.gz', 'package of current kept');

        self::assertSame(2, $this->console(['cleanup', '--keep=0'])->exit);
        self::assertSame(2, $this->console(['cleanup', '--keep'])->exit);
    }
}
