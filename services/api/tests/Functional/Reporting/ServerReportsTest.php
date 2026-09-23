<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Reporting;

use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;
use Psr\Http\Message\ResponseInterface;

/**
 * The reports for a server holding an API key with `reports:read` (a WordPress admin, say):
 * the same controller as the dashboard's routes, so the same bodies, behind the key's scope and
 * its site.
 */
final class ServerReportsTest extends HttpTestCase
{
    private Site $site;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['timezone' => 'Europe/Rome'], ['www.site.test']);
        [, $this->secret] = $this->service(ApiKeyService::class)->create($this->site->id(), 'wordpress', ['reports:read'], null);
    }

    private function server(string $report, string $query = '', ?string $secret = null): ResponseInterface
    {
        $path = '/api/v1/server/sites/' . $this->site->publicKey . '/reports/' . $report . ($query !== '' ? '?' . $query : '');
        $headers = $secret === '' ? [] : ['Authorization' => 'Bearer ' . ($secret ?? $this->secret)];

        return $this->request('GET', $path, null, $headers);
    }

    /** Two visitors, three visits, four pageviews, one custom event. */
    private function ingest(): void
    {
        $key = $this->site->publicKey;
        $this->clock->modify('2026-09-16 08:00:00');
        $this->collect(Payloads::batch($key, [
            Payloads::pageview('https://www.site.test/', 'https://www.google.com/'),
            Payloads::pageview('https://www.site.test/pricing', 'https://www.site.test/'),
            Payloads::event('signup_click', ['plan' => 'pro'], 'https://www.site.test/pricing'),
        ]), [], '203.0.113.10');
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/blog/post')]), ['User-Agent' => Payloads::IPHONE_UA], '198.51.100.20');
        $this->clock->modify('2026-09-17 09:00:00');
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/pricing?utm_source=newsletter&utm_medium=email')]), [], '203.0.113.10');
        $this->clock->modify('2026-09-17 10:00:00');
        $this->service(RollupRunner::class)->runDirty($this->site->id());
    }

    public function testTheKeyIsRequiredScopedAndBoundToItsSite(): void
    {
        $this->assertProblem($this->server('overview', '', ''), 401, 'unauthorized');
        $this->assertProblem($this->server('overview', '', 'ak_12345678_' . str_repeat('a', 43)), 401, 'unauthorized');

        $keys = $this->service(ApiKeyService::class);
        [, $writeOnly] = $keys->create($this->site->id(), 'write', ['conversions:write', 'stats:read'], null);
        $this->assertProblem($this->server('overview', '', $writeOnly), 403, 'forbidden');

        $other = $this->factory->site([], ['other.test']);
        [, $otherSecret] = $keys->create($other->id(), 'other', ['reports:read'], null);
        $this->assertProblem($this->server('overview', '', $otherSecret), 404, 'not_found');
    }

    public function testTheSameBodiesAsTheDashboardRoutes(): void
    {
        $this->ingest();
        $viewer = $this->factory->user();
        $this->factory->grant($viewer, $this->site, SiteRole::Viewer);
        $this->loginAs($viewer);
        $session = '/api/v1/sites/' . $this->site->id() . '/reports/';

        foreach ([
            ['overview', 'period=7d&compare=previous_period'],
            ['timeseries', 'period=7d&interval=day'],
            ['pages', 'period=7d&kind=top'],
            ['sources', 'period=7d&group=channel'],
            ['tech', 'period=7d&group=device'],
            ['countries', 'period=7d'],
            ['events', 'period=7d'],
            ['conversions', 'period=7d'],
            ['consent', 'period=7d'],
        ] as [$report, $query]) {
            $byKey = $this->server($report, $query);
            $this->assertStatus(200, $byKey);
            $key = $this->json($byKey);
            $dashboard = $this->json($this->get($session . $report . '?' . $query));
            // The second of the two calls finds the report in the cache: everything else is the same.
            unset($key['meta']['cache'], $dashboard['meta']['cache']);
            self::assertSame($dashboard, $key, $report);
        }

        $overview = $this->json($this->server('overview', 'period=7d'));
        self::assertSame(4, $overview['data']['metrics']['pageviews']);
    }

    public function testRealtimeAndACustomPeriod(): void
    {
        $this->ingest();
        $this->assertStatus(200, $this->server('realtime'));
        $custom = $this->json($this->server('overview', 'period=custom&from=2026-09-16&to=2026-09-16'));
        self::assertSame(3, $custom['data']['metrics']['pageviews']);
        $this->assertProblem($this->server('overview', 'period=nonsense'), 422);
    }

    public function testDashboardOnlyReportsAreNotExposed(): void
    {
        $this->validateOpenApi = false;
        $this->assertStatus(404, $this->server('cohorts'));
        $this->assertStatus(404, $this->server('attribution'));
    }
}
