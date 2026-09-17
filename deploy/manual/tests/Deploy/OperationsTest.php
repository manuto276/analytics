<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Deploy;

use AnalyticsDeploy\Tests\Support\DeployTestCase;
use AnalyticsDeploy\Tests\Support\PackageBuilder;

final class OperationsTest extends DeployTestCase
{
    public function testInitCreatesLayoutAndIsIdempotent(): void
    {
        $r = $this->console(['init']);
        self::assertSame(0, $r->exit, $r->output());
        foreach (['packages', 'releases', 'shared', 'shared/var/log', 'shared/var/storage/geo', 'shared/var/storage/locks', 'shared/var/storage/backups', '.deploy'] as $dir) {
            self::assertDirectoryExists($this->root . '/' . $dir);
            self::assertSame(0750, fileperms($this->root . '/' . $dir) & 0777, $dir);
        }
        self::assertSame(0600, fileperms($this->root . '/shared/.env') & 0777);
        self::assertSame('', file_get_contents($this->root . '/shared/.env'));
        self::assertSame(0640, fileperms($this->root . '/deploy.ini') & 0777);
        self::assertSame(0750, fileperms($this->root . '/console') & 0777);
        self::assertStringContainsString('shared[] = ".env"', (string) file_get_contents($this->root . '/deploy.ini'));

        file_put_contents($this->root . '/shared/.env', "SECRET=1\n");
        file_put_contents($this->root . '/deploy.ini', "keep_releases = 7\n");
        $before = $this->snapshot($this->root);
        $r = $this->console(['init']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('kept existing deploy.ini', $r->stdout);
        self::assertSame("SECRET=1\n", file_get_contents($this->root . '/shared/.env'));
        self::assertSame("keep_releases = 7\n", file_get_contents($this->root . '/deploy.ini'));
        unset($before['.deploy/lock']);
        $after = $this->snapshot($this->root);
        unset($after['.deploy/lock']);
        self::assertSame($before, $after);
    }

    public function testInitUsesEnvExampleFromPackage(): void
    {
        mkdir($this->root . '/packages');
        $this->addPackage(PackageBuilder::make(self::ts(1)));
        $r = $this->console(['init']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('DATABASE_URL=mysql://', (string) file_get_contents($this->root . '/shared/.env'));
        self::assertSame(0600, fileperms($this->root . '/shared/.env') & 0777);
    }

    public function testCommandsRequireInit(): void
    {
        $r = $this->console(['deploy']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('not initialised', $r->stderr);
    }

    public function testLockContention(): void
    {
        $this->init();
        $this->addPackage(PackageBuilder::make(self::ts(1)));
        $ready = $this->work . '/locked';
        $release = $this->work . '/release';
        $code = '$f = fopen($argv[1], "c+"); flock($f, LOCK_EX); ftruncate($f, 0); fwrite($f, "pid 4242, deploy"); fflush($f); touch($argv[2]); while (!file_exists($argv[3])) usleep(10000);';
        $proc = proc_open([PHP_BINARY, '-r', $code, '--', $this->root . '/.deploy/lock', $ready, $release], [], $pipes);
        $deadline = microtime(true) + 10;
        while (!file_exists($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }

        foreach ([['deploy'], ['rollback'], ['cleanup'], ['self-update']] as $args) {
            $r = $this->console($args);
            self::assertSame(1, $r->exit, implode(' ', $args));
            self::assertStringContainsString('Another deploy console operation is running', $r->stderr);
            self::assertStringContainsString('pid 4242, deploy', $r->stderr);
        }
        self::assertNull($this->currentTarget());

        touch($release);
        proc_close($proc);
        $this->assertDeployOk($this->console(['deploy']));
    }

    public function testSelfUpdate(): void
    {
        $this->init();
        $console = (string) file_get_contents(self::consoleSource());
        $newer = str_replace("const VERSION = '1.0.0';", "const VERSION = '9.9.9';", $console);
        self::assertNotSame($console, $newer);
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))->file('deploy/console', $newer, 0755)));

        $r = $this->console(['self-update']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('1.0.0 -> 9.9.9', $r->stdout);
        self::assertSame($newer, file_get_contents($this->root . '/console'));
        self::assertSame($console, file_get_contents($this->root . '/.deploy/console.previous'));
        self::assertSame(0750, fileperms($this->root . '/console') & 0777);
        self::assertStringContainsString('9.9.9', $this->console(['--version'])->stdout);

        $r = $this->console(['self-update']);
        self::assertSame(0, $r->exit);
        self::assertStringContainsString('already up to date', $r->stdout);
    }

    public function testSelfUpdateRejectsBrokenConsole(): void
    {
        $this->init();
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))->file('deploy/console', "<?php this is not php\n", 0755)));
        $before = file_get_contents($this->root . '/console');

        $r = $this->console(['self-update']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('failed validation', $r->stderr);
        self::assertSame($before, file_get_contents($this->root . '/console'));
        self::assertFileDoesNotExist($this->root . '/.deploy/console.new');
    }

    public function testListAndStatus(): void
    {
        $this->init();
        $r = $this->console(['status']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('Current:     none', $r->stdout);

        $a = PackageBuilder::make(self::ts(1));
        $b = PackageBuilder::make(self::ts(2));
        $this->assertDeployOk($this->deploy($a));
        $this->assertDeployOk($this->deploy($b));
        $this->addPackage(PackageBuilder::make(self::ts(3)));
        unlink($this->root . '/packages/analytics-' . self::ts(3) . '.tar.gz.sha256');

        $r = $this->console(['list']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertMatchesRegularExpression('/' . self::ts(2) . '\s+' . substr($b->commit, 0, 12) . '\s+built \S+\s+\[current\]/', $r->stdout);
        self::assertMatchesRegularExpression('/' . self::ts(1) . '\s+' . substr($a->commit, 0, 12) . '.*\[previous\]/', $r->stdout);
        self::assertMatchesRegularExpression('/analytics-' . self::ts(3) . '\.tar\.gz\s+\S+ KiB\s+\[missing \.sha256\]/', $r->stdout);
        self::assertMatchesRegularExpression('/analytics-' . self::ts(2) . '\.tar\.gz\s+\S+ KiB\s+\[extracted\]/', $r->stdout);

        $r = $this->console(['status']);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('Current:     ' . self::ts(2), $r->stdout);
        self::assertStringContainsString('Commit:      ' . $b->commit, $r->stdout);
        self::assertStringContainsString('Built at:    2026-09-01T10:05:00Z', $r->stdout);
        self::assertStringContainsString('Previous:    ' . self::ts(1), $r->stdout);
        self::assertStringContainsString('Releases:    2', $r->stdout);
        self::assertStringContainsString('Health:      not configured', $r->stdout);
        self::assertMatchesRegularExpression('/deploy\s+' . self::ts(2) . '\s+success\s+previous=' . self::ts(1) . '/', $r->stdout);
    }

    public function testUsageErrors(): void
    {
        self::assertSame(2, $this->console([])->exit);
        self::assertSame(2, $this->console(['frobnicate'])->exit);
        self::assertSame(2, $this->console(['deploy', '--no-such-flag'])->exit);
        self::assertSame(2, $this->console(['list', 'extra'])->exit);
        $r = $this->console(['help']);
        self::assertSame(0, $r->exit);
        self::assertStringContainsString('rollback [release] [--force]', $r->stdout);
        $r = $this->console(['--version']);
        self::assertSame("analytics deploy console 1.0.0\n", $r->stdout);
    }

    public function testInvalidSharedItemIsRejected(): void
    {
        $this->init();
        foreach (['/etc', '../outside', 'var/../..'] as $item) {
            $this->writeIni(['shared' => ['.env', $item]]);
            $r = $this->console(['deploy']);
            self::assertSame(1, $r->exit, $item);
            self::assertStringContainsString('Invalid shared item', $r->stderr);
        }
    }

    public function testDeployRootDefaultsToConsoleDirectory(): void
    {
        $proc = proc_open([PHP_BINARY, $this->root . '/console', 'init'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->work, array_diff_key(getenv(), ['ANALYTICS_DEPLOY_ROOT' => true]));
        self::assertSame(0, proc_close($proc));
        self::assertDirectoryExists($this->root . '/releases');
    }
}
