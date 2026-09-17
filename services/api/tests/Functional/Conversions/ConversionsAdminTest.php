<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Conversions;

use Analytics\Identity\Domain\SiteRole;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\HttpTestCase;

final class ConversionsAdminTest extends HttpTestCase
{
    private Site $site;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site([], ['www.site.test']);
        $this->base = '/api/v1/sites/' . $this->site->id();
        $admin = $this->factory->user();
        $this->factory->grant($admin, $this->site, SiteRole::Admin);
        $this->loginAs($admin);
    }

    public function testApiKeyLifecycle(): void
    {
        $created = $this->data($this->post($this->base . '/api-keys', ['name' => 'backend', 'scopes' => ['conversions:write']]), 201);
        self::assertMatchesRegularExpression('/^ak_[A-Za-z0-9]{8}_[A-Za-z0-9_-]{43}$/', $created['secret']);
        self::assertSame(['conversions:write'], $created['scopes']);

        $list = $this->data($this->get($this->base . '/api-keys'));
        self::assertCount(1, $list);
        self::assertArrayNotHasKey('secret', $list[0], 'the secret is shown only once');

        $this->assertProblem($this->post($this->base . '/api-keys', ['name' => 'x', 'scopes' => ['bogus']]), 422);
        $this->assertProblem($this->post($this->base . '/api-keys', ['name' => 'x', 'scopes' => []]), 422);
        $this->assertProblem($this->post($this->base . '/api-keys', ['name' => 'x', 'scopes' => ['stats:read'], 'expires_at' => '2020-01-01T00:00:00Z']), 422);

        $this->assertStatus(204, $this->delete($this->base . '/api-keys/' . $created['id']));
        self::assertNotNull($this->data($this->get($this->base . '/api-keys'))[0]['revoked_at']);
        $this->assertProblem($this->delete($this->base . '/api-keys/999999'), 404);
    }

    public function testGoalsAndFunnelsCrud(): void
    {
        $pageGoal = $this->data($this->post($this->base . '/goals', ['name' => 'Pricing seen', 'type' => 'pageview', 'match' => ['path' => '/pricing*']]), 201);
        $eventGoal = $this->data($this->post($this->base . '/goals', ['name' => 'Signup click', 'type' => 'event', 'match' => ['name' => 'signup_click', 'props' => ['plan' => 'pro']]]), 201);
        $this->data($this->post($this->base . '/goals', ['name' => 'Purchase', 'type' => 'conversion', 'match' => ['name' => 'purchase']]), 201);
        self::assertCount(3, $this->data($this->get($this->base . '/goals')));

        $this->assertProblem($this->post($this->base . '/goals', ['name' => 'Pricing seen', 'type' => 'pageview', 'match' => ['path' => '/x']]), 409, 'goal_name_taken');
        $this->assertProblem($this->post($this->base . '/goals', ['name' => 'Bad', 'type' => 'pageview', 'match' => ['path' => 'no-slash']]), 422);
        $this->assertProblem($this->post($this->base . '/goals', ['name' => 'Bad', 'type' => 'unknown', 'match' => []]), 422);

        $renamed = $this->data($this->patch($this->base . '/goals/' . $pageGoal['id'], ['name' => 'Pricing page']));
        self::assertSame('Pricing page', $renamed['name']);

        $funnel = $this->data($this->post($this->base . '/funnels', ['name' => 'Signup funnel', 'scope' => 'visit', 'window_days' => 7, 'goal_ids' => [$pageGoal['id'], $eventGoal['id']]]), 201);
        self::assertSame([1, 2], array_column($funnel['steps'], 'position'));
        self::assertSame('Pricing page', $funnel['steps'][0]['goal_name']);

        $this->assertProblem($this->post($this->base . '/funnels', ['name' => 'Too short', 'goal_ids' => [$pageGoal['id']]]), 422);
        $this->assertProblem($this->post($this->base . '/funnels', ['name' => 'Unknown goal', 'goal_ids' => [$pageGoal['id'], 999999]]), 422);
        $this->assertProblem($this->delete($this->base . '/goals/' . $pageGoal['id']), 409, 'goal_in_use');

        $this->assertStatus(204, $this->delete($this->base . '/funnels/' . $funnel['id']));
        $this->assertStatus(204, $this->delete($this->base . '/goals/' . $pageGoal['id']));
        self::assertCount(2, $this->data($this->get($this->base . '/goals')));
    }

    public function testCostsCrudAndCsvImport(): void
    {
        $cost = $this->data($this->post($this->base . '/costs', [
            'day_from' => '2026-09-01', 'day_to' => '2026-09-30', 'channel' => 'paid_search',
            'utm_source' => 'google', 'amount_minor' => 150000, 'currency' => 'EUR', 'note' => 'Brand campaign',
        ]), 201);
        self::assertSame(150000, $cost['amount_minor']);
        $updated = $this->data($this->patch($this->base . '/costs/' . $cost['id'], ['amount_minor' => 120000]));
        self::assertSame(120000, $updated['amount_minor']);
        self::assertSame('paid_search', $updated['channel'], 'unchanged fields are preserved');
        self::assertCount(1, $this->data($this->get($this->base . '/costs?from=2026-09-01&to=2026-09-30')));
        self::assertCount(0, $this->data($this->get($this->base . '/costs?from=2026-10-01&to=2026-10-31')));

        $csv = "day_from,day_to,channel,utm_source,utm_medium,utm_campaign,amount,currency,note\n"
            . "2026-09-01,2026-09-15,paid_social,facebook,paid_social,retarget,1234.56,EUR,September\n"
            . "2026-09-16,,paid_search,google,cpc,brand,90,EUR,\n";
        $preview = $this->data($this->request('POST', $this->base . '/costs/import?dry_run=1', $csv, ['Content-Type' => 'text/csv']));
        self::assertTrue($preview['dry_run']);
        self::assertFalse($preview['imported']);
        self::assertSame(2, $preview['valid_rows']);
        self::assertSame(123456, $preview['rows'][0]['amount_minor']);
        self::assertSame('2026-09-16', $preview['rows'][1]['day_to'], 'day_to defaults to day_from');
        self::assertCount(1, $this->data($this->get($this->base . '/costs')), 'the preview imports nothing');

        $imported = $this->data($this->request('POST', $this->base . '/costs/import', $csv, ['Content-Type' => 'text/csv']));
        self::assertTrue($imported['imported']);
        self::assertNotNull($imported['batch_id']);
        self::assertSame(2, $imported['valid_rows']);
        self::assertCount(3, $this->data($this->get($this->base . '/costs')), 'one manual entry plus two imported rows');

        $invalid = $this->data($this->request('POST', $this->base . '/costs/import', "day_from,amount,currency\n2026-13-99,abc,EU\n", ['Content-Type' => 'text/csv']));
        self::assertSame(1, $invalid['invalid_rows']);
        self::assertFalse($invalid['imported']);
        self::assertGreaterThanOrEqual(3, \count($invalid['rows'][0]['errors']), 'invalid date, amount and currency are all reported');

        $this->assertProblem($this->request('POST', $this->base . '/costs/import', "nope\n1\n", ['Content-Type' => 'text/csv']), 422);
        $this->assertStatus(204, $this->delete($this->base . '/costs/' . $cost['id']));
    }

    public function testViewerCannotManage(): void
    {
        $viewer = $this->factory->user();
        $this->factory->grant($viewer, $this->site, SiteRole::Viewer);
        $this->loginAs($viewer);
        $this->data($this->get($this->base . '/goals'));
        $this->assertProblem($this->get($this->base . '/api-keys'), 403);
        $this->assertProblem($this->post($this->base . '/goals', ['name' => 'x', 'type' => 'pageview', 'match' => ['path' => '/x']]), 403);
    }
}
