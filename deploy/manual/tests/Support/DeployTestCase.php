<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class DeployTestCase extends TestCase
{
    protected string $work;
    protected string $root;
    protected string $appLog;
    private ?HealthServer $health = null;

    public static function consoleSource(): string
    {
        return \dirname(__DIR__, 2) . '/console';
    }

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        $this->work = $base . '/analytics-deploy-test-' . bin2hex(random_bytes(5));
        mkdir($this->work . '/root', 0755, true);
        $this->root = $this->work . '/root';
        $this->appLog = $this->work . '/app.log';
        copy(self::consoleSource(), $this->root . '/console');
        chmod($this->root . '/console', 0755);
    }

    protected function tearDown(): void
    {
        $this->health?->stop();
        $this->health = null;
        self::rmTree($this->work);
    }

    /** @param list<string> $args @param array<string, string> $env */
    protected function console(array $args, array $env = []): RunResult
    {
        $fullEnv = array_merge(getenv(), ['FAKE_APP_LOG' => $this->appLog, 'ANALYTICS_DEPLOY_ROOT' => $this->root], $env);
        $proc = proc_open(
            array_merge([PHP_BINARY, $this->root . '/console'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->work . '/stdout', 'w'], 2 => ['file', $this->work . '/stderr', 'w']],
            $pipes,
            $this->work,
            $fullEnv,
        );
        $exit = proc_close($proc);

        return new RunResult($exit, (string) file_get_contents($this->work . '/stdout'), (string) file_get_contents($this->work . '/stderr'));
    }

    /** Initialises the deploy root and writes a test deploy.ini. @param array<string, scalar> $ini */
    protected function init(array $ini = []): void
    {
        $r = $this->console(['init']);
        self::assertSame(0, $r->exit, $r->output());
        $this->writeIni($ini);
    }

    /** @param array<string, scalar|list<string>> $overrides */
    protected function writeIni(array $overrides = []): void
    {
        $values = array_merge([
            'php_binary' => PHP_BINARY,
            'keep_releases' => 5,
            'keep_packages' => 3,
            'health_url' => '',
            'health_tries' => 2,
            'health_interval' => 0.05,
            'health_timeout' => 2,
            'auto_rollback' => 'true',
            'backup_before_migrate' => 'false',
            'shared' => ['.env', 'var/log', 'var/storage'],
        ], $overrides);
        $ini = '';
        foreach ($values as $k => $v) {
            if (\is_array($v)) {
                foreach ($v as $item) {
                    $ini .= sprintf("%s[] = \"%s\"\n", $k, $item);
                }
            } else {
                $ini .= sprintf("%s = %s\n", $k, \is_string($v) && !\in_array($v, ['true', 'false'], true) ? '"' . $v . '"' : (string) $v);
            }
        }
        file_put_contents($this->root . '/deploy.ini', $ini);
    }

    protected function startHealth(): HealthServer
    {
        mkdir($this->work . '/health', 0755, true);
        $this->health = new HealthServer($this->root, $this->work . '/health');

        return $this->health;
    }

    protected static function ts(int $n): string
    {
        return sprintf('202609%02dT120000Z', $n);
    }

    protected function addPackage(PackageBuilder $builder): string
    {
        return $builder->build($this->root . '/packages');
    }

    protected function deploy(PackageBuilder $builder, array $extraArgs = [], array $env = []): RunResult
    {
        $this->addPackage($builder);

        return $this->console(array_merge(['deploy', $builder->name()], $extraArgs), $env);
    }

    protected function assertDeployOk(RunResult $r): void
    {
        self::assertSame(0, $r->exit, "deploy failed:\n" . $r->output());
    }

    protected function currentTarget(): ?string
    {
        clearstatcache(true);

        return is_link($this->root . '/current') ? (string) readlink($this->root . '/current') : null;
    }

    /** @return list<array<string, mixed>> */
    protected function history(): array
    {
        $file = $this->root . '/.deploy/history.jsonl';
        if (!is_file($file)) {
            return [];
        }

        return array_map(static fn (string $l): array => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /** @return list<array<string, mixed>> */
    protected function appCalls(): array
    {
        if (!is_file($this->appLog)) {
            return [];
        }

        return array_map(static fn (string $l): array => json_decode($l, true), file($this->appLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /** @return list<string> */
    protected function releaseDirs(): array
    {
        $out = array_values(array_filter(scandir($this->root . '/releases'), static fn (string $e): bool => $e !== '.' && $e !== '..'));
        sort($out);

        return $out;
    }

    /** Relative path => type:perms:linktarget:size:mtime for everything under $dir. @return array<string, string> */
    protected function snapshot(string $dir): array
    {
        clearstatcache(true);
        $out = [];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (scandir($path) as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $p = $path . '/' . $e;
                $r = ltrim($rel . '/' . $e, '/');
                $st = lstat($p);
                if (is_link($p)) {
                    $out[$r] = 'link:' . readlink($p);
                } elseif (is_dir($p)) {
                    $out[$r] = sprintf('dir:%o:%d', $st['mode'] & 0777, $st['mtime']);
                    $walk($p, $r);
                } else {
                    $out[$r] = sprintf('file:%o:%d:%d:%s', $st['mode'] & 0777, $st['size'], $st['mtime'], md5_file($p));
                }
            }
        };
        $walk($dir, '');
        ksort($out);

        return $out;
    }

    protected static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach (scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') {
                self::rmTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }
}
