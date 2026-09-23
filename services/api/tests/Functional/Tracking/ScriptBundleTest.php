<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Tracking;

use Analytics\Consent\Application\ConsentService;
use Analytics\Kernel\Console\PreflightCommand;
use Analytics\Shared\Validation\Input;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\RecordingLogger;
use Analytics\Tests\Support\TestDatabase;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The served /t/{key}.js: the banner module travels only with sites that have the cookie level on,
 * and a missing build artefact is loud (logged, and reported by app:preflight).
 */
final class ScriptBundleTest extends HttpTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/analytics-scripts-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function files(bool $tracker = true, bool $banner = true): void
    {
        if ($tracker) {
            file_put_contents($this->dir . '/tracker.js', "/*! analytics | header */\n/*core*/window.__core=1;\n");
        }
        if ($banner) {
            file_put_contents($this->dir . '/banner.js', "/*! analytics | header */\n/*banner*/window.__an_b=function(){};\n");
        }
    }

    private function builder(?RecordingLogger $logger = null, bool $strict = false): ScriptBundleBuilder
    {
        $pool = $this->container->get(CacheItemPoolInterface::class);
        \assert($pool instanceof CacheItemPoolInterface);

        return new ScriptBundleBuilder($this->service(ConsentService::class), $pool, $this->dir . '/tracker.js', 'https://example.org/source', $this->dir . '/banner.js', $logger, $strict);
    }

    private function publishedSite(bool $cookieLevel): SiteSnapshot
    {
        $site = $this->factory->site(['cookieLevelEnabled' => $cookieLevel]);
        $consent = $this->service(ConsentService::class);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $consent->publish($site->id(), false, 1);

        return SiteSnapshot::fromSite($site);
    }

    public function testTheBannerModuleComesBeforeTheCoreOnlyWithTheCookieLevel(): void
    {
        $this->files();
        $withConsent = $this->builder()->build($this->publishedSite(true))['body'];
        $banner = strpos($withConsent, '/*banner*/');
        $core = strpos($withConsent, '/*core*/');
        self::assertIsInt($banner);
        self::assertIsInt($core);
        self::assertLessThan($core, $banner, 'the banner registers itself before the core runs');
        self::assertLessThan($banner, (int) strpos($withConsent, 'window.__an_cfg='), 'after the config');
        self::assertSame(1, substr_count($withConsent, '/*!'), 'the build headers are replaced by the bundle header');
        self::assertStringContainsString('"css":".b,.f{position:fixed', $withConsent);
        self::assertStringContainsString('"ri":"M21 12', $withConsent);

        $without = $this->builder()->build($this->publishedSite(false))['body'];
        self::assertStringNotContainsString('/*banner*/', $without, 'sites without the cookie level get the smaller script');
        self::assertStringContainsString('/*core*/', $without);
        self::assertStringContainsString('"consent":null', $without);
    }

    public function testTheEtagCoversTheBannerModule(): void
    {
        $this->files();
        $site = $this->publishedSite(true);
        $first = $this->builder()->build($site);
        file_put_contents($this->dir . '/banner.js', "/*banner v2*/window.__an_b=function(){};\n");
        $second = $this->builder()->build($site);
        self::assertNotSame($first['etag'], $second['etag']);
        self::assertStringContainsString('/*banner v2*/', $second['body']);

        $plain = $this->publishedSite(false);
        $before = $this->builder()->build($plain)['etag'];
        file_put_contents($this->dir . '/banner.js', "/*banner v3*/\n");
        self::assertSame($before, $this->builder()->build($plain)['etag'], 'a site without the banner is not invalidated by it');
    }

    public function testMissingFilesAreLoggedAsErrorsInProduction(): void
    {
        $logger = new RecordingLogger();
        $site = $this->publishedSite(true);
        $bundle = $this->builder($logger, strict: true)->build($site);
        self::assertStringContainsString(ScriptBundleBuilder::STUB, $bundle['body'], 'pages keep working with the stub');
        $errors = array_values(array_filter($logger->records, static fn(array $r): bool => $r['level'] === 'error'));
        self::assertCount(2, $errors, 'the core and the banner');
        self::assertSame($this->dir . '/tracker.js', $errors[0]['context']['path']);
        self::assertSame($this->dir . '/banner.js', $errors[1]['context']['path']);

        $quiet = new RecordingLogger();
        $this->builder($quiet, strict: false)->build($site);
        self::assertSame([], $quiet->records, 'development: the stub is expected before pnpm build:api');

        $this->files(banner: false);
        $onlyCore = new RecordingLogger();
        $body = $this->builder($onlyCore, strict: true)->build($site)['body'];
        self::assertStringContainsString('/*core*/', $body);
        self::assertCount(1, $onlyCore->records);
        self::assertSame($this->dir . '/banner.js', $onlyCore->records[0]['context']['path']);
    }

    public function testPreflightReportsMissingScripts(): void
    {
        $connection = $this->container->get(Connection::class);
        \assert($connection instanceof Connection);
        $run = function (array $env) use ($connection): array {
            $tester = new CommandTester(new PreflightCommand(TestDatabase::settings($env), $connection, $this->builder()));
            $status = $tester->execute(['--skip-db' => true, '--json' => true]);
            $json = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($json);
            $checks = [];
            foreach ($json['checks'] as $check) {
                if (str_starts_with($check['name'], 'tracker:')) {
                    $checks[$check['name']] = [$check['ok'], $check['required']];
                }
            }

            return [$status, $checks];
        };

        [, $checks] = $run([]);
        self::assertSame(['tracker:tracker.js' => [false, false], 'tracker:banner.js' => [false, false]], $checks, 'outside production: warnings');

        $prod = ['APP_ENV' => 'prod', 'APP_URL' => 'http://localhost', 'APP_SECRET' => base64_encode(str_repeat("\x01", 32)), 'APP_ENCRYPTION_KEYS' => 'k1:' . base64_encode(str_repeat("\x02", 32))];
        [$status, $checks] = $run($prod);
        self::assertSame(Command::FAILURE, $status, 'a release without its scripts must not go live');
        self::assertSame(['tracker:tracker.js' => [false, true], 'tracker:banner.js' => [false, true]], $checks);

        $this->files();
        [, $checks] = $run($prod);
        self::assertSame(['tracker:tracker.js' => [true, true], 'tracker:banner.js' => [true, true]], $checks);
    }
}
