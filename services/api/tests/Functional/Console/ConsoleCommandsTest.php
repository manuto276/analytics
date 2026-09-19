<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Console;

use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\UserStatus;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Sites\Domain\VisitorHashMode;
use Analytics\Tests\Support\ConsoleTestCase;
use Analytics\Tracking\Application\Seeder;
use Symfony\Component\Console\Command\Command;

final class ConsoleCommandsTest extends ConsoleTestCase
{
    /** Feeds a password to commands that read it from standard input. */
    private function withPassword(string $command, string $password): void
    {
        $instance = $this->buildApplication()->find($command);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $password);
        rewind($stream);
        self::assertTrue(property_exists($instance, 'passwordStream'), $command . ' does not read a password');
        $instance->passwordStream = $stream;
    }

    public function testUserLifecycleCommands(): void
    {
        $this->withPassword('user:create-admin', 'a very strong passphrase');
        $output = $this->console('user:create-admin', ['--email' => 'Boss@Example.com', '--name' => 'Boss', '--password-stdin' => true, '--locale' => 'it']);
        self::assertStringContainsString('Admin boss@example.com created', $output);

        $user = $this->service(\Analytics\Identity\Application\UserService::class)->findByEmail('boss@example.com');
        self::assertNotNull($user);
        self::assertSame(GlobalRole::Admin, $user->globalRole);
        self::assertSame('it', $user->locale);

        // Duplicates and weak passwords are refused.
        $this->withPassword('user:create-admin', 'a very strong passphrase');
        $this->console('user:create-admin', ['--email' => 'boss@example.com', '--password-stdin' => true], Command::FAILURE);
        $this->withPassword('user:create-admin', 'short');
        $this->console('user:create-admin', ['--email' => 'other@example.com', '--password-stdin' => true], Command::INVALID);
        $this->console('user:create-admin', ['--email' => 'not-an-email', '--password-stdin' => true], Command::INVALID);

        self::assertStringContainsString('boss@example.com', $this->console('user:list'));

        $this->withPassword('user:set-password', 'another strong passphrase');
        self::assertStringContainsString('Password updated', $this->console('user:set-password', ['email' => 'boss@example.com', '--password-stdin' => true]));
        $this->em->refresh($user);
        self::assertTrue($this->service(\Analytics\Identity\Application\PasswordHasher::class)->verify('another strong passphrase', $user->passwordHash));

        // The last admin cannot be disabled, a second one can.
        $this->console('user:disable', ['email' => 'boss@example.com'], Command::FAILURE);
        $member = $this->factory->user(email: 'member@example.com');
        self::assertStringContainsString('disabled', $this->console('user:disable', ['email' => 'member@example.com']));
        $this->em->refresh($member);
        self::assertSame(UserStatus::Disabled, $member->status);
        self::assertStringContainsString('enabled', $this->console('user:disable', ['email' => 'member@example.com', '--enable' => true]));
        $this->console('user:disable', ['email' => 'nobody@example.com'], Command::FAILURE);

        self::assertStringContainsString('removed', $this->console('user:reset-2fa', ['email' => 'boss@example.com']));
        $this->console('user:reset-2fa', ['email' => 'nobody@example.com'], Command::FAILURE);
    }

    public function testSiteCommands(): void
    {
        $output = $this->console('site:create', [
            '--name' => 'Example', '--domain' => ['www.example.com', '*.shop.example.com'],
            '--timezone' => 'Europe/Rome', '--hash-mode' => 'pageviews_only', '--currency' => 'CHF',
        ]);
        self::assertStringContainsString('Site "Example" created', $output);
        self::assertSame(1, preg_match('/Public key: (pk_[A-Za-z0-9]{21})/', $output, $m));
        self::assertStringContainsString('<script defer src="https://analytics.test/t/' . $m[1] . '.js">', $output);

        $sites = $this->service(SiteRepository::class);
        $site = $sites->findByPublicKey($m[1]);
        self::assertNotNull($site);
        self::assertSame('Europe/Rome', $site->timezone);
        self::assertSame('CHF', $site->currency);
        self::assertSame(VisitorHashMode::PageviewsOnly, $site->visitorHashMode);
        $hosts = array_map(static fn(\Analytics\Sites\Domain\SiteDomain $d): string => $d->host, $site->domains->toArray());
        sort($hosts);
        self::assertSame(['shop.example.com', 'www.example.com'], $hosts);
        self::assertTrue($site->domains->toArray()[1]->includeSubdomains, 'the *. prefix enables subdomains');

        $this->console('site:create', ['--name' => 'Bad', '--domain' => ['not a host']], Command::FAILURE);
        $this->console('site:create', ['--name' => 'Bad', '--hash-mode' => 'nope'], Command::INVALID);

        self::assertStringContainsString('Example', $this->console('site:list'));
        $json = json_decode($this->console('site:show', ['site' => $m[1]]), true);
        self::assertIsArray($json);
        self::assertSame('Example', $json['name']);
        $this->console('site:show', ['site' => 'pk_' . str_repeat('Z', 21)], Command::FAILURE);

        self::assertStringContainsString('added', $this->console('site:domain:add', ['site' => (string) $site->id(), 'host' => 'blog.example.com', '--include-subdomains' => true]));
        self::assertStringContainsString('removed', $this->console('site:domain:remove', ['site' => (string) $site->id(), 'host' => 'blog.example.com']));
        self::assertStringContainsString('Domain news.example.com added', $this->console('site:domain:add', ['site' => (string) $site->id(), 'host' => '*.news.example.com']));
        $news = array_values(array_filter($site->domains->toArray(), static fn(\Analytics\Sites\Domain\SiteDomain $d): bool => $d->host === 'news.example.com'));
        self::assertCount(1, $news);
        self::assertTrue($news[0]->includeSubdomains, 'site:domain:add parses the *. prefix like site:create and the API');
        self::assertStringContainsString('removed', $this->console('site:domain:remove', ['site' => (string) $site->id(), 'host' => '*.news.example.com']));
        $this->console('site:domain:add', ['site' => (string) $site->id(), 'host' => '*.'], Command::FAILURE);
        $this->console('site:domain:remove', ['site' => (string) $site->id(), 'host' => 'unknown.example.com'], Command::FAILURE);
    }

    public function testInvitationAndApiKeyCommands(): void
    {
        $site = $this->factory->site([], ['www.site.test']);
        $output = $this->console('invitation:create', ['--email' => 'viewer@example.com', '--role' => 'viewer', '--site' => [(string) $site->id()]]);
        self::assertSame(1, preg_match('#https://analytics\.test/invite/([A-Za-z0-9_-]{43})#', $output, $m));
        $invitation = $this->service(\Analytics\Identity\Application\InvitationService::class)->findPending($m[1]);
        self::assertSame([['site_id' => $site->id(), 'role' => 'viewer']], $invitation->siteRoles);

        $this->console('invitation:create', ['--email' => 'bad'], Command::INVALID);
        $this->console('invitation:create', ['--email' => 'x@example.com', '--site' => ['pk_' . str_repeat('Z', 21)]], Command::FAILURE);

        $keyOutput = $this->console('api-key:create', ['--site' => $site->publicKey, '--name' => 'backend', '--scopes' => 'conversions:write,stats:read']);
        self::assertSame(1, preg_match('/^(ak_[A-Za-z0-9]{8}_[A-Za-z0-9_-]{43})$/m', $keyOutput, $key));
        $verified = $this->service(\Analytics\Conversions\Application\ApiKeyService::class)->verify($key[1]);
        self::assertNotNull($verified);
        self::assertSame(['conversions:write', 'stats:read'], $verified->scopes);

        $this->console('api-key:create', ['--site' => $site->publicKey, '--scopes' => 'nope'], Command::FAILURE);
        self::assertStringContainsString('revoked', $this->console('api-key:revoke', ['prefix' => $verified->prefix, '--site' => (string) $site->id()]));
        $this->console('api-key:revoke', ['prefix' => 'zzzzzzzz', '--site' => (string) $site->id()], Command::FAILURE);
        self::assertNull($this->service(\Analytics\Conversions\Application\ApiKeyService::class)->verify($key[1]));
    }

    public function testOperationalCommands(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true], ['www.example.com']);
        $this->service(Seeder::class)->seed(SiteSnapshot::fromSite($site), $this->clock->now(), 2, 3, 5);

        self::assertStringContainsString('salt:rotate succeeded', $this->console('salt:rotate'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM daily_salts'));

        $this->console('rollup:run', ['-v' => true]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty'));
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_overview_daily WHERE site_id = ?', [$site->id()]));
        $this->console('rollup:run', ['--site' => 'pk_' . str_repeat('Z', 21)], Command::FAILURE);

        $rebuild = $this->console('rollup:rebuild', ['--site' => (string) $site->id(), '--from' => $this->clock->now()->modify('-2 days')->format('Y-m-d'), '--to' => $this->clock->now()->format('Y-m-d')]);
        self::assertStringContainsString('rollup:rebuild succeeded', $rebuild);
        $this->console('rollup:rebuild', ['--site' => (string) $site->id(), '--from' => 'nope', '--to' => 'nope'], Command::INVALID);
        $this->console('rollup:rebuild', ['--site' => '999999'], Command::INVALID);

        $status = $this->console('jobs:status');
        self::assertStringContainsString('rollup:run', $status);
        self::assertStringContainsString('rollup lag: 0 min', $status);
        $json = json_decode($this->console('jobs:status', ['--json' => true]), true);
        self::assertIsArray($json);
        self::assertSame(0, $json['dirty_days']);

        self::assertStringContainsString('conversions:reattribute succeeded', $this->console('conversions:reattribute', ['--site' => (string) $site->id()]));
        $this->console('conversions:reattribute', ['--site' => 'unknown'], Command::INVALID);
    }

    public function testSeedAndHealthAndSecrets(): void
    {
        $site = $this->factory->site([], ['www.example.com']);
        $output = $this->console('dev:seed', ['--site' => $site->publicKey, '--days' => '2', '--visits' => '3', '--seed' => '99']);
        self::assertStringContainsString('Seeded', $output);
        self::assertStringContainsString('Rollups built.', $output);
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE site_id = ?', [$site->id()]));
        $this->console('dev:seed', ['--site' => 'unknown'], Command::INVALID);

        $health = $this->console('health:check');
        self::assertStringContainsString('overall:', $health);
        $healthJson = json_decode($this->console('health:check', ['--json' => true]), true);
        self::assertIsArray($healthJson);
        self::assertSame('ok', $healthJson['checks']['database']['status']);
        self::assertSame('ok', $healthJson['checks']['migrations']['status']);

        $preflight = $this->console('app:preflight', ['--json' => true]);
        $preflightJson = json_decode($preflight, true);
        self::assertIsArray($preflightJson);
        self::assertTrue($preflightJson['ok']);
        self::assertStringContainsString('[ok]', $this->console('app:preflight', ['--skip-db' => true]));

        $secrets = $this->console('secrets:generate');
        self::assertSame(1, preg_match('/^APP_SECRET=([A-Za-z0-9+\/=]{44})$/m', $secrets));
        self::assertStringContainsString('APP_ENCRYPTION_KEYS=', $secrets);

        self::assertStringContainsString('Re-encrypted 0 secret', $this->console('secrets:rotate-key'));
        self::assertStringContainsString('Cache cleared', $this->console('cache:clear'));
        self::assertStringContainsString('Cache warmed up', $this->console('cache:warmup'));
        self::assertStringContainsString('[OK]', $this->console('orm:validate-schema'));
    }

    public function testGeoCommands(): void
    {
        $settings = $this->service(\Analytics\Kernel\Settings::class);
        $fixture = \dirname(__DIR__, 2) . '/fixtures/GeoIP2-Country-Test.mmdb.gz';
        self::assertFileExists($fixture);
        @unlink($settings->geoDbPath);

        $output = $this->console('geo:update', ['--url' => 'file://' . $fixture, '--force' => true]);
        self::assertStringContainsString('geo:update succeeded', $output);
        self::assertFileExists($settings->geoDbPath);

        // A second run without --force keeps the file (it is from this month).
        self::assertStringContainsString('current', $this->console('geo:update', ['--url' => 'file://' . $fixture]));

        $lookup = $this->console('geo:lookup', ['ip' => '2a02:ff00:1:2:3::9']);
        self::assertStringContainsString('2a02:ff00:1::/48 → IT', $lookup);
        self::assertStringContainsString('unknown', $this->console('geo:lookup', ['ip' => '10.0.0.1']));
        $this->console('geo:lookup', ['ip' => 'not-an-ip'], \Symfony\Component\Console\Command\Command::INVALID);

        $this->console('geo:update', ['--url' => 'file:///does/not/exist-%s.mmdb.gz', '--force' => true], \Symfony\Component\Console\Command\Command::FAILURE);
        @unlink($settings->geoDbPath);
    }

    public function testQueueWorkNeedsRedis(): void
    {
        self::assertStringContainsString('REDIS_DSN', $this->console('queue:work', ['--once' => true], Command::FAILURE));
    }
}
