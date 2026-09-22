<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Deploy;

use AnalyticsDeploy\Tests\Support\DeployTestCase;
use AnalyticsDeploy\Tests\Support\PackageBuilder;

final class DeployFlowTest extends DeployTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->init();
        file_put_contents($this->root . '/shared/.env', "APP_ENV=prod\n");
    }

    public function testSuccessfulDeploy(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $r = $this->deploy($pkg);
        $this->assertDeployOk($r);

        self::assertSame('releases/' . self::ts(1), $this->currentTarget());
        self::assertFileExists($this->root . '/current/public/index.php');
        self::assertSame([self::ts(1)], $this->releaseDirs());

        $calls = $this->appCalls();
        self::assertSame(['app:preflight', 'cache:warmup', 'migrations:migrate', 'cache:warmup'], array_column($calls, 'cmd'));
        self::assertSame(['migrations:migrate', '--no-interaction', '--allow-no-migration'], $calls[2]['args']);
        foreach (array_slice($calls, 0, 3) as $call) {
            self::assertSame('.tmp-' . self::ts(1), $call['release'], 'checks and migrations run before the rename');
        }
        // A compiled container keeps absolute paths, so the one built in .tmp-<TS> would point at a
        // directory that no longer exists: the last warmup runs where the release really lives.
        self::assertSame(self::ts(1), $calls[3]['release'], 'the container is compiled again after the rename');

        $h = $this->history();
        self::assertCount(1, $h);
        self::assertSame('deploy', $h[0]['action']);
        self::assertSame(self::ts(1), $h[0]['release']);
        self::assertSame($pkg->commit, $h[0]['commit']);
        self::assertNull($h[0]['previous']);
        self::assertSame('success', $h[0]['result']);
        self::assertNotEmpty($h[0]['user']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $h[0]['ts']);
    }

    public function testSharedItemsAreRelativeSymlinksThatResolve(): void
    {
        $pkg = PackageBuilder::make(self::ts(1))->file('.env', "SHOULD_BE_REPLACED=1\n")->dir('var/log')->file('var/log/old.log', 'x');
        $this->assertDeployOk($this->deploy($pkg));
        $release = $this->root . '/releases/' . self::ts(1);

        self::assertTrue(is_link($release . '/.env'));
        self::assertSame('../../shared/.env', readlink($release . '/.env'));
        self::assertSame('../../../shared/var/log', readlink($release . '/var/log'));
        self::assertSame('../../../shared/var/storage', readlink($release . '/var/storage'));
        self::assertSame(realpath($this->root . '/shared/.env'), realpath($this->root . '/current/.env'));
        self::assertSame(realpath($this->root . '/shared/var/storage'), realpath($this->root . '/current/var/storage'));
        self::assertSame("APP_ENV=prod\n", file_get_contents($this->root . '/current/.env'));
        self::assertFileDoesNotExist($this->root . '/shared/var/log/old.log');
    }

    public function testPermissions(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $release = $this->root . '/releases/' . self::ts(1);
        clearstatcache();
        self::assertSame(0750, fileperms($release . '/public') & 0777);
        self::assertSame(0640, fileperms($release . '/public/index.php') & 0777);
        self::assertSame(0750, fileperms($release . '/bin/analytics') & 0777);
        self::assertSame(0600, fileperms($this->root . '/shared/.env') & 0777);
        self::assertSame(0750, fileperms($this->root . '/shared/var/storage/backups') & 0777);
        self::assertSame(0640, fileperms($this->root . '/deploy.ini') & 0777);
    }

    public function testFailedMigrationLeavesCurrentUntouched(): void
    {
        $a = PackageBuilder::make(self::ts(1));
        $this->assertDeployOk($this->deploy($a));

        $b = PackageBuilder::make(self::ts(2));
        $r = $this->deploy($b, [], ['FAKE_APP_FAIL' => 'migrations:migrate']);
        self::assertSame(1, $r->exit, $r->output());
        self::assertStringContainsString('migrations:migrate failed', $r->stderr);
        self::assertStringContainsString('current is unchanged (' . self::ts(1) . ')', $r->stdout);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget());
        self::assertSame([self::ts(1)], $this->releaseDirs(), 'tmp dir removed, no new release');
        $last = $this->history()[1];
        self::assertSame(['deploy', self::ts(2), 'failed', self::ts(1)], [$last['action'], $last['release'], $last['result'], $last['previous']]);
    }

    public function testFailedPreflightStopsBeforeMigrations(): void
    {
        $r = $this->deploy(PackageBuilder::make(self::ts(1)), [], ['FAKE_APP_FAIL' => 'app:preflight']);
        self::assertSame(1, $r->exit);
        self::assertNull($this->currentTarget());
        self::assertSame([], $this->releaseDirs());
        self::assertSame(['app:preflight'], array_column($this->appCalls(), 'cmd'));
    }

    public function testMissingSharedEnvFailsPreflight(): void
    {
        unlink($this->root . '/shared/.env');
        $r = $this->deploy(PackageBuilder::make(self::ts(1)));
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('shared/.env does not exist', $r->stderr);
    }

    public function testSwitchUsesRelativeSymlinkAndRenameOverCurrent(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(2))));
        self::assertSame('releases/' . self::ts(2), readlink($this->root . '/current'));
        self::assertFalse(is_link($this->root . '/current.tmp') || file_exists($this->root . '/current.tmp'));
        // a stale current.tmp from an interrupted switch is replaced
        symlink('releases/nowhere', $this->root . '/current.tmp');
        self::assertSame(0, $this->console(['rollback'])->exit);
        self::assertSame('releases/' . self::ts(1), readlink($this->root . '/current'));
    }

    public function testCurrentThatIsNotASymlinkIsRefused(): void
    {
        mkdir($this->root . '/current');
        $r = $this->deploy(PackageBuilder::make(self::ts(1)));
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('current exists but is not a symlink', $r->stderr);
    }

    public function testNoMigrate(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1)), ['--no-migrate']));
        self::assertSame(['app:preflight', 'cache:warmup', 'cache:warmup'], array_column($this->appCalls(), 'cmd'));
    }

    public function testTheReleaseCacheIsEmptiedBeforeTheFinalWarmup(): void
    {
        $pkg = PackageBuilder::make(self::ts(1))->dir('var/cache/container/stale')->file('var/cache/container/stale/CompiledContainer.php', '<?php // compiled for .tmp-');
        $this->assertDeployOk($this->deploy($pkg));
        self::assertDirectoryExists($this->root . '/releases/' . self::ts(1) . '/var/cache');
        self::assertFileDoesNotExist($this->root . '/releases/' . self::ts(1) . '/var/cache/container/stale/CompiledContainer.php');
    }

    public function testAFailingFinalWarmupLeavesCurrentAlone(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $r = $this->deploy(PackageBuilder::make(self::ts(2)), [], ['FAKE_APP_FAIL_FINAL' => 'cache:warmup']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('cache:warmup failed', $r->stderr);
        self::assertSame('releases/' . self::ts(1), $this->currentTarget(), 'current still points at the previous release');
        self::assertSame([self::ts(1)], $this->releaseDirs(), 'the half-built release is removed, not left under its final name');
        self::assertSame('failed', $this->history()[1]['result']);
    }

    public function testNewestPackageIsDeployedByDefault(): void
    {
        $this->addPackage(PackageBuilder::make(self::ts(3)));
        $this->addPackage(PackageBuilder::make(self::ts(1)));
        $r = $this->console(['deploy']);
        $this->assertDeployOk($r);
        self::assertSame('releases/' . self::ts(3), $this->currentTarget());
    }

    public function testRedeployOfCurrentIsNoop(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $this->assertDeployOk($this->deploy($pkg));
        $r = $this->console(['deploy', $pkg->name()]);
        self::assertSame(0, $r->exit);
        self::assertStringContainsString('already current', $r->stdout);
        self::assertCount(4, $this->appCalls(), 'the first deploy only: preflight, warmup, migrate, final warmup');
    }

    public function testDryRunChangesNothing(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $pkg = PackageBuilder::make(self::ts(2));
        $this->addPackage($pkg);
        $before = $this->snapshot($this->root);
        $calls = \count($this->appCalls());

        $r = $this->console(['deploy', '--dry-run']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('switch current: ' . self::ts(1) . ' -> ' . self::ts(2), $r->stdout);
        self::assertStringContainsString('run bin/analytics migrations:migrate', $r->stdout);
        self::assertSame($before, $this->snapshot($this->root));
        self::assertCount($calls, $this->appCalls());
    }

    public function testDryRunReportsInvalidPackage(): void
    {
        $this->addPackage(PackageBuilder::make(self::ts(1))->wrongChecksum());
        $before = $this->snapshot($this->root);
        $r = $this->console(['deploy', '--dry-run']);
        self::assertSame(1, $r->exit);
        self::assertSame($before, $this->snapshot($this->root));
    }

    public function testBackupBeforeMigrate(): void
    {
        $dump = $this->work . '/fake-mysqldump';
        file_put_contents($dump, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"" . $this->work . "/dump-args\"\ncat \"\$(printf '%s' \"\$1\" | sed 's/^--defaults-extra-file=//')\" > \"" . $this->work . "/dump-cnf\"\necho '-- fake dump'\n");
        chmod($dump, 0755);
        $this->writeIni(['mysqldump_binary' => $dump]);
        file_put_contents($this->root . '/shared/.env', "DATABASE_URL=\"mysql://an%40user:p%23ss@db.internal:3307/analytics_prod\"\n");

        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1)), ['--backup']));

        $args = file($this->work . '/dump-args', FILE_IGNORE_NEW_LINES);
        self::assertStringStartsWith('--defaults-extra-file=', $args[0]);
        self::assertContains('--single-transaction', $args);
        self::assertContains('--ignore-table=analytics_prod.daily_salts', $args);
        self::assertSame('analytics_prod', end($args));
        $cnf = (string) file_get_contents($this->work . '/dump-cnf');
        self::assertStringContainsString('user="an@user"', $cnf);
        self::assertStringContainsString('password="p#ss"', $cnf);
        self::assertStringContainsString('port=3307', $cnf);
        self::assertFileDoesNotExist(substr($args[0], \strlen('--defaults-extra-file=')), 'credentials file removed');

        $backup = $this->root . '/shared/var/storage/backups/' . self::ts(1) . '.sql.gz';
        self::assertFileExists($backup);
        self::assertSame("-- fake dump\n", gzdecode((string) file_get_contents($backup)));
        $cmds = array_column($this->appCalls(), 'cmd');
        self::assertSame(['migrations:migrate', 'cache:warmup'], array_slice($cmds, -2), 'migrations run after the backup, then the final warmup');
    }

    public function testFailedBackupAbortsDeploy(): void
    {
        $dump = $this->work . '/fake-mysqldump';
        file_put_contents($dump, "#!/bin/sh\necho 'access denied' >&2\nexit 2\n");
        chmod($dump, 0755);
        $this->writeIni(['mysqldump_binary' => $dump, 'backup_before_migrate' => 'true']);
        file_put_contents($this->root . '/shared/.env', "DB_HOST=localhost\nDB_NAME=analytics\nDB_USER=u\nDB_PASSWORD='secret'\n");

        $r = $this->deploy(PackageBuilder::make(self::ts(1)));
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('mysqldump failed with exit code 2: access denied', $r->stderr);
        self::assertNull($this->currentTarget());
        self::assertNotContains('migrations:migrate', array_column($this->appCalls(), 'cmd'));
        self::assertSame([], glob($this->root . '/shared/var/storage/backups/*'));
    }

    public function testAtomicSwitchNeverExposesMissingCurrent(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            // APFS (macOS) lets concurrent lookups see EINVAL while rename() replaces a symlink,
            // even for a plain symlink+rename loop; the guarantee is a Linux/ext4/xfs property.
            self::markTestSkipped('rename() over a symlink is only atomic for readers on Linux; run the suite in a Linux container');
        }
        $a = PackageBuilder::make(self::ts(1));
        $b = PackageBuilder::make(self::ts(2));
        $c = PackageBuilder::make(self::ts(3));
        $this->assertDeployOk($this->deploy($a));
        $this->addPackage($b);
        $this->addPackage($c);

        $stop = $this->work . '/reader.stop';
        $result = $this->work . '/reader.json';
        $code = <<<'PHP'
            [$root, $stop, $result] = array_slice($argv, 1);
            $n = 0; $missing = 0; $targets = [];
            file_put_contents($result . '.started', '1');
            while (!file_exists($stop)) {
                clearstatcache(true);
                $t = @readlink($root . '/current');
                if ($t === false || !is_dir($root . '/current') || !is_file($root . '/current/BUILD_INFO.json')) { $missing++; }
                else { $targets[$t] = true; }
                $n++;
            }
            file_put_contents($result, json_encode(['n' => $n, 'missing' => $missing, 'targets' => array_keys($targets)]));
            PHP;
        $proc = proc_open([PHP_BINARY, '-r', $code, '--', $this->root, $stop, $result], [], $pipes);
        $deadline = microtime(true) + 10;
        while (!file_exists($result . '.started') && microtime(true) < $deadline) {
            usleep(10000);
        }

        $this->assertDeployOk($this->console(['deploy', $b->name()]));
        $this->assertDeployOk($this->console(['rollback']));
        $this->assertDeployOk($this->console(['deploy', $c->name()]));
        touch($stop);
        proc_close($proc);

        $data = json_decode((string) file_get_contents($result), true);
        self::assertGreaterThan(100, $data['n']);
        self::assertSame(0, $data['missing'], 'reader saw a missing current');
        self::assertContains('releases/' . self::ts(3), $data['targets']);
    }

    public function testAppPassthrough(): void
    {
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))));
        $r = $this->console(['app', 'user:list', '--format=json']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('fake user:list ok', $r->stdout);
        $last = $this->appCalls()[4];
        self::assertSame(['user:list', '--format=json'], $last['args']);
        self::assertSame(self::ts(1), $last['release']);

        $r = $this->console(['app', 'boom'], ['FAKE_APP_FAIL' => 'boom']);
        self::assertSame(1, $r->exit);
    }

    public function testAppWithoutCurrentFails(): void
    {
        $r = $this->console(['app', 'user:list']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('No current release', $r->stderr);
    }
}
