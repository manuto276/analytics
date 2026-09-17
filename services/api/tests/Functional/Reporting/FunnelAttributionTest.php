<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Reporting;

use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class FunnelAttributionTest extends HttpTestCase
{
    private Site $site;
    private string $base;
    private string $apiSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['cookieLevelEnabled' => true, 'timezone' => 'Europe/Rome'], ['www.site.test']);
        $this->base = '/api/v1/sites/' . $this->site->id();
        $admin = $this->factory->user();
        $this->factory->grant($admin, $this->site, SiteRole::Admin);
        $this->loginAs($admin);
        [, $this->apiSecret] = $this->service(ApiKeyService::class)->create($this->site->id(), 'backend', ['conversions:write'], null);
    }

    /** @return array{0: string, 1: string} visitor id and session id */
    private function visitor(): array
    {
        return [Payloads::id22(), Payloads::id22()];
    }

    public function testFunnelCountsStepsInOrder(): void
    {
        $pricing = $this->data($this->post($this->base . '/goals', ['name' => 'Pricing', 'type' => 'pageview', 'match' => ['path' => '/pricing*']]), 201);
        $signup = $this->data($this->post($this->base . '/goals', ['name' => 'Signup', 'type' => 'event', 'match' => ['name' => 'signup_click']]), 201);
        $funnel = $this->data($this->post($this->base . '/funnels', ['name' => 'Signup funnel', 'scope' => 'visit', 'window_days' => 30, 'goal_ids' => [$pricing['id'], $signup['id']]]), 201);
        $key = $this->site->publicKey;

        // A completes both steps in order.
        [$vidA, $sidA] = $this->visitor();
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/pricing')], 'c', ['vid' => $vidA, 'sid' => $sidA, 'cv' => 1]), [], '203.0.113.1');
        $this->clock->sleep(60);
        $this->collect(Payloads::batch($key, [Payloads::event('signup_click', [], 'https://www.site.test/pricing')], 'c', ['vid' => $vidA, 'sid' => $sidA, 'cv' => 1]), [], '203.0.113.1');

        // B stops at the first step.
        [$vidB, $sidB] = $this->visitor();
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/pricing')], 'c', ['vid' => $vidB, 'sid' => $sidB, 'cv' => 1]), [], '198.51.100.2');

        // C fires the event before ever seeing the pricing page: not in the funnel.
        [$vidC, $sidC] = $this->visitor();
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/'), Payloads::event('signup_click')], 'c', ['vid' => $vidC, 'sid' => $sidC, 'cv' => 1]), [], '192.0.2.3');

        $report = $this->data($this->get($this->base . '/reports/funnels/' . $funnel['id'] . '?period=7d'));
        self::assertSame(2, $report['entered']);
        self::assertSame(1, $report['completed']);
        self::assertSame(0.5, $report['conversion_rate']);
        self::assertSame([
            ['position' => 1, 'name' => 'Pricing', 'count' => 2, 'drop_off' => 0],
            ['position' => 2, 'name' => 'Signup', 'count' => 1, 'drop_off' => 1],
        ], array_map(static fn(array $s): array => ['position' => $s['position'], 'name' => $s['name'], 'count' => $s['count'], 'drop_off' => $s['drop_off']], $report['steps']));

        $withBreakdown = $this->data($this->get($this->base . '/reports/funnels/' . $funnel['id'] . '?period=7d&breakdown=device'));
        self::assertSame([['value' => 'desktop', 'steps' => [2, 1]]], $withBreakdown['breakdown']);

        $this->assertProblem($this->get($this->base . '/reports/funnels/999999?period=7d'), 404);
    }

    public function testAttributionWithCostsCacAndRoas(): void
    {
        $key = $this->site->publicKey;
        // Two visitors arriving from a paid campaign, one from a newsletter.
        $visitors = [];
        foreach ([['google', 'cpc', 'brand', '203.0.113.1'], ['google', 'cpc', 'brand', '198.51.100.2'], ['newsletter', 'email', 'september', '192.0.2.3']] as [$source, $medium, $campaign, $ip]) {
            [$vid, $sid] = $this->visitor();
            $visitors[] = $vid;
            $url = \sprintf('https://www.site.test/?utm_source=%s&utm_medium=%s&utm_campaign=%s', $source, $medium, $campaign);
            $this->collect(Payloads::batch($key, [Payloads::pageview($url)], 'c', ['vid' => $vid, 'sid' => $sid, 'cv' => 1]), [], $ip);
        }

        // Signups for all three, purchases for the two paid ones.
        foreach ($visitors as $index => $vid) {
            $this->request('POST', '/api/v1/server/sites/' . $key . '/conversions', ['id' => 'signup-' . $index, 'name' => 'signup', 'visitor_id' => $vid], ['Authorization' => 'Bearer ' . $this->apiSecret]);
        }
        foreach ([0, 1] as $index) {
            $this->request('POST', '/api/v1/server/sites/' . $key . '/conversions', [
                'id' => 'purchase-' . $index,
                'name' => 'purchase',
                'visitor_id' => $visitors[$index],
                'value' => ['amount_minor' => 30000, 'currency' => 'EUR'],
            ], ['Authorization' => 'Bearer ' . $this->apiSecret]);
        }
        // 300.00 EUR spent on the paid campaign inside the reporting range.
        $this->post($this->base . '/costs', ['day_from' => '2026-09-17', 'day_to' => '2026-09-17', 'channel' => 'paid_search', 'utm_source' => 'google', 'utm_campaign' => 'brand', 'amount_minor' => 30000, 'currency' => 'EUR']);

        $report = $this->data($this->get($this->base . '/reports/attribution?period=7d&model=first_touch&group=channel&window=30&base=signup&target=purchase'));
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[(string) $row['key']] = $row;
        }
        self::assertSame(2, $rows['paid_search']['base_conversions']);
        self::assertSame(2, $rows['paid_search']['target_conversions']);
        self::assertSame(60000, $rows['paid_search']['revenue_minor']);
        self::assertSame(30000, $rows['paid_search']['cost_minor']);
        self::assertSame(15000, $rows['paid_search']['cac_minor'], '300.00 EUR for two purchases');
        self::assertSame(2.0, $rows['paid_search']['roas'], '600.00 EUR revenue on 300.00 EUR spend');
        self::assertSame(1, $rows['email']['base_conversions']);
        self::assertSame(0, $rows['email']['target_conversions']);
        self::assertNull($rows['email']['cost_minor']);
        self::assertSame(3, $report['totals']['base_conversions']);
        self::assertSame(0.0, $report['totals']['unattributed_share']);

        $byCampaign = $this->data($this->get($this->base . '/reports/attribution?period=7d&group=campaign&base=signup&target=purchase'));
        self::assertSame(['brand', 'september'], array_column($byCampaign['rows'], 'key'));

        $lastTouch = $this->data($this->get($this->base . '/reports/attribution?period=7d&model=last_non_direct&base=signup&target=purchase'));
        self::assertSame(3, $lastTouch['totals']['base_conversions']);

        $this->assertProblem($this->get($this->base . '/reports/attribution?model=bogus'), 422);
        $this->assertProblem($this->get($this->base . '/reports/attribution?window=5'), 422);
    }

    /**
     * Campaign spend is prorated over local days. The report range carries midnight in the site's
     * time zone while day_from/day_to are plain dates, so a campaign that covers the whole range
     * must still contribute its whole budget, in any time zone.
     */
    public function testCampaignCostProrationDoesNotLoseADayToTheSiteTimeZone(): void
    {
        $this->post($this->base . '/costs', ['day_from' => '2026-01-01', 'day_to' => '2026-01-31', 'channel' => 'paid_search', 'utm_source' => 'google', 'utm_campaign' => 'winter', 'amount_minor' => 100000, 'currency' => 'EUR']);
        // Ten days of a second campaign, of which only six fall inside the range below.
        $this->post($this->base . '/costs', ['day_from' => '2026-01-01', 'day_to' => '2026-01-10', 'channel' => 'paid_social', 'utm_source' => 'facebook', 'utm_campaign' => 'launch', 'amount_minor' => 100000, 'currency' => 'EUR']);

        foreach (['Europe/Rome', 'America/New_York', 'UTC'] as $timezone) {
            $this->site->timezone = $timezone;
            $this->em->flush();
            $this->clearCaches();

            $full = $this->costsByChannel('2026-01-01', '2026-01-31');
            self::assertSame(100000, $full['paid_search'], 'the whole budget belongs to a range that covers the whole campaign (' . $timezone . ')');
            self::assertSame(100000, $full['paid_social'], 'a campaign inside the range keeps its whole budget (' . $timezone . ')');

            $partial = $this->costsByChannel('2026-01-05', '2026-01-31');
            self::assertSame(87097, $partial['paid_search'], '27 of the 31 campaign days overlap the range (' . $timezone . ')');
            self::assertSame(60000, $partial['paid_social'], '6 of the 10 campaign days overlap the range (' . $timezone . ')');
        }
    }

    /** @return array<string, int> channel => cost_minor */
    private function costsByChannel(string $from, string $to): array
    {
        $report = $this->data($this->get($this->base . '/reports/attribution?period=custom&from=' . $from . '&to=' . $to . '&group=channel'));
        $costs = [];
        foreach ($report['rows'] as $row) {
            $costs[(string) $row['key']] = $row['cost_minor'];
        }
        self::assertSame(array_sum($costs), $report['totals']['cost_minor']);

        return $costs;
    }

    public function testGoalsReportCountsEveryGoalType(): void
    {
        $key = $this->site->publicKey;
        $this->data($this->post($this->base . '/goals', ['name' => 'Pricing', 'type' => 'pageview', 'match' => ['path' => '/pricing*']]), 201);
        $this->data($this->post($this->base . '/goals', ['name' => 'Pro signup', 'type' => 'event', 'match' => ['name' => 'signup_click', 'props' => ['plan' => 'pro']]]), 201);
        $this->data($this->post($this->base . '/goals', ['name' => 'Purchase', 'type' => 'conversion', 'match' => ['name' => 'purchase']]), 201);

        [$vid, $sid] = $this->visitor();
        $this->collect(Payloads::batch($key, [
            Payloads::pageview('https://www.site.test/pricing'),
            Payloads::event('signup_click', ['plan' => 'pro'], 'https://www.site.test/pricing'),
        ], 'c', ['vid' => $vid, 'sid' => $sid, 'cv' => 1]), [], '203.0.113.9');
        $this->collect(Payloads::batch($key, [Payloads::event('signup_click', ['plan' => 'free'])]), [], '198.51.100.9');
        $this->request('POST', '/api/v1/server/sites/' . $key . '/conversions', ['id' => 'p1', 'name' => 'purchase', 'visitor_id' => $vid, 'value' => ['amount_minor' => 5000, 'currency' => 'EUR']], ['Authorization' => 'Bearer ' . $this->apiSecret]);
        $this->service(\Analytics\Reporting\Application\Rollup\RollupRunner::class)->runDirty($this->site->id());

        $rows = $this->data($this->get($this->base . '/reports/goals?period=7d'))['rows'];
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }
        self::assertSame(1, $byName['Pricing']['conversions']);
        self::assertSame(1, $byName['Pro signup']['conversions'], 'the free plan event does not match');
        self::assertSame(1, $byName['Purchase']['conversions']);
        self::assertSame(5000, $byName['Purchase']['value_minor']);
        self::assertSame(0.5, $byName['Pricing']['conversion_rate'], 'one of the two visits');
    }
}
