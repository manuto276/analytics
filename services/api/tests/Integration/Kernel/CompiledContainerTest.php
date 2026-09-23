<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Kernel;

use Analytics\Kernel\ContainerFactory;
use Analytics\Kernel\Settings;
use Analytics\Tests\Support\TestDatabase;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Production compiles the container to disk, and a compiled container holds absolute paths. The
 * deploy console once warmed it up in releases/.tmp-<TS> and then renamed the directory, so every
 * request looked for the tracker in a directory that no longer existed and served the no-op stub.
 */
final class CompiledContainerTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/analytics-compiled-' . bin2hex(random_bytes(4));
        mkdir($this->scratch . '/cache', 0750, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->scratch));
    }

    public function testAContainerCompiledForAnotherDirectoryIsNeverReused(): void
    {
        // Compiled first where the tracker does not exist — the renamed temporary directory.
        $temporary = $this->release('.tmp-20260919T102728Z', withTracker: false);
        $before = ContainerFactory::create($this->settings($temporary))->get(ScriptBundleBuilder::class);
        \assert($before instanceof ScriptBundleBuilder);
        // No tracker there (reading it would serve the stub and log an error: this is production).
        self::assertSame([$temporary . '/resources/tracker/tracker.js' => false, $temporary . '/resources/tracker/banner.js' => false], $before->files());

        // The same shared cache, the directory the release really lives in.
        $final = $this->release('20260919T102728Z', withTracker: true);
        $after = ContainerFactory::create($this->settings($final))->get(ScriptBundleBuilder::class);
        \assert($after instanceof ScriptBundleBuilder);
        self::assertSame('/* the real tracker */', trim($after->tracker()['code']), 'the release\'s own tracker, not the stub compiled for the other path');
        self::assertSame([$final . '/resources/tracker/tracker.js' => true, $final . '/resources/tracker/banner.js' => false], $after->files(), 'the banner module is looked up in the same release');

        self::assertNotSame(
            ContainerFactory::compilationDir($this->settings($temporary)),
            ContainerFactory::compilationDir($this->settings($final)),
        );
        self::assertCount(2, glob($this->scratch . '/cache/container/*', \GLOB_ONLYDIR) ?: [], 'one compiled container per release directory');
    }

    private function release(string $name, bool $withTracker): string
    {
        $dir = $this->scratch . '/releases/' . $name;
        mkdir($dir . '/resources/tracker', 0750, true);
        if ($withTracker) {
            file_put_contents($dir . '/resources/tracker/tracker.js', "/*! header */\n/* the real tracker */\n");
        }

        return $dir;
    }

    private function settings(string $projectDir): Settings
    {
        return Settings::fromEnvironment([
            'APP_ENV' => 'prod',
            'APP_URL' => 'https://analytics.example.net',
            'APP_SECRET' => base64_encode(str_repeat("\x01", 32)),
            'APP_ENCRYPTION_KEYS' => 'k1:' . base64_encode(str_repeat("\x02", 32)),
            'DATABASE_URL' => TestDatabase::url(),
            // Shared across the two "releases", as a CACHE_DIR pointing into shared/ would be.
            'CACHE_DIR' => $this->scratch . '/cache',
            'LOG_DIR' => $this->scratch . '/log',
            'STORAGE_DIR' => $this->scratch . '/storage',
        ], $projectDir);
    }
}
