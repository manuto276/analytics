<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Conversions;

use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Shared\Crypto\Base64Url;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class ServerConversionsTest extends HttpTestCase
{
    private Site $site;
    private string $secret;
    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['cookieLevelEnabled' => true, 'timezone' => 'Europe/Rome'], ['www.site.test']);
        [, $this->secret] = $this->service(ApiKeyService::class)->create($this->site->id(), 'backend', ['conversions:write', 'stats:read'], null);
        $this->endpoint = '/api/v1/server/sites/' . $this->site->publicKey . '/conversions';
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $body */
    private function send(array $body, ?string $secret = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', $this->endpoint, $body, ['Authorization' => 'Bearer ' . ($secret ?? $this->secret)]);
    }

    public function testApiKeyIsRequiredAndScoped(): void
    {
        $body = ['id' => 'order-1', 'name' => 'purchase'];
        $this->assertProblem($this->request('POST', $this->endpoint, $body), 401, 'unauthorized');
        $this->assertProblem($this->send($body, 'ak_12345678_' . str_repeat('a', 43)), 401, 'unauthorized');
        $this->assertProblem($this->send($body, 'nonsense'), 401, 'unauthorized');

        $keys = $this->service(ApiKeyService::class);
        [, $readOnly] = $keys->create($this->site->id(), 'read', ['stats:read'], null);
        $this->assertProblem($this->send($body, $readOnly), 403, 'forbidden');

        $otherSite = $this->factory->site([], ['other.test']);
        [, $otherSecret] = $keys->create($otherSite->id(), 'other', ['conversions:write'], null);
        $this->assertProblem($this->send($body, $otherSecret), 404, 'not_found');

        [$revoked, $revokedSecret] = $keys->create($this->site->id(), 'revoked', ['conversions:write'], null);
        $keys->revoke($this->site->id(), $revoked->id());
        $this->assertProblem($this->send($body, $revokedSecret), 401);

        [$expired, $expiredSecret] = $keys->create($this->site->id(), 'expired', ['conversions:write'], null, $this->clock->now()->modify('+1 hour'));
        $this->clock->sleep(7200);
        $this->assertProblem($this->send($body, $expiredSecret), 401);
        self::assertNotNull($expired->expiresAt);
    }

    public function testSingleAndBatchIngestWithIdempotency(): void
    {
        // The mixed batch below intentionally contains entries that do not match the documented body.
        $this->validateOpenApi = false;
        $response = $this->send(['id' => 'order-1', 'name' => 'purchase', 'value' => ['amount_minor' => 4900, 'currency' => 'EUR'], 'props' => ['plan' => 'pro']]);
        $this->assertStatus(202, $response);
        self::assertSame(['accepted' => 1, 'duplicates' => 0, 'rejected' => []], $this->json($response));

        $batch = $this->send([
            ['id' => 'order-1', 'name' => 'purchase'],
            ['id' => 'order-2', 'name' => 'signup'],
            ['id' => '', 'name' => 'purchase'],
            ['id' => 'order-3', 'name' => 'NOT VALID'],
            ['id' => 'order-4', 'name' => 'purchase', 'occurred_at' => 'not-a-date'],
            ['id' => 'order-5', 'name' => 'purchase', 'value' => ['amount_minor' => 100, 'currency' => 'eur']],
            'nonsense',
        ]);
        $body = $this->json($batch);
        self::assertSame(1, $body['accepted']);
        self::assertSame(1, $body['duplicates']);
        self::assertCount(5, $body['rejected']);
        self::assertSame([2, 3, 4, 5, 6], array_column($body['rejected'], 'index'));

        $rows = $this->db->fetchAllAssociative('SELECT external_id, name, value_minor, currency, origin, local_day, props, attr_channel FROM conversions WHERE site_id = ? ORDER BY id', [$this->site->id()]);
        self::assertCount(2, $rows);
        self::assertSame(['order-1', 'order-2'], array_column($rows, 'external_id'));
        self::assertSame('4900', (string) $rows[0]['value_minor']);
        self::assertSame('server', $rows[0]['origin']);
        self::assertSame('2026-09-17', $rows[0]['local_day']);
        self::assertSame(['plan' => 'pro'], json_decode((string) $rows[0]['props'], true));
        self::assertSame('unattributed', $rows[0]['attr_channel']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ?', [$this->site->id()]));
    }

    public function testRejectsOversizedBatchesAndBadJson(): void
    {
        $this->assertProblem($this->send(array_fill(0, 101, ['id' => 'x', 'name' => 'purchase'])), 400, 'too_many_conversions');
        $this->assertProblem($this->send([]), 400, 'empty_batch');
        $this->assertProblem($this->request('POST', $this->endpoint, 'not json', ['Authorization' => 'Bearer ' . $this->secret, 'Content-Type' => 'application/json']), 400, 'invalid_json');
    }

    public function testAttributionFromVisitorTouchesAndCustomerRef(): void
    {
        $key = $this->site->publicKey;
        $vid = Payloads::id22();
        $sid = Payloads::id22();
        // First touch: paid search campaign.
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/?utm_source=google&utm_medium=cpc&utm_campaign=brand', 'https://www.google.com/')], 'c', ['vid' => $vid, 'sid' => $sid, 'cv' => 1]));
        // Later touch: newsletter.
        $this->clock->sleep(2 * 86400);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/pricing?utm_source=newsletter&utm_medium=email')], 'c', ['vid' => $vid, 'sid' => Payloads::id22(), 'cv' => 1]));

        $this->send(['id' => 'signup-1', 'name' => 'signup', 'visitor_id' => $vid, 'customer_ref' => 'customer-42']);
        $row = $this->db->fetchAssociative('SELECT * FROM conversions WHERE external_id = ?', ['signup-1']);
        self::assertIsArray($row);
        self::assertSame('visitor', $row['attr_via']);
        self::assertSame('paid_search', $row['attr_channel'], 'first touch');
        self::assertSame('brand', $row['attr_utm_campaign']);
        self::assertSame('email', $row['lnd_channel'], 'last non-direct touch');
        self::assertSame(Base64Url::decode($vid), $row['visitor_id']);
        self::assertNotNull($row['customer_ref']);
        self::assertSame(32, \strlen((string) $row['customer_ref']));
        self::assertStringNotContainsString('customer-42', (string) $row['customer_ref']);

        // A later purchase without a visitor id inherits the attribution through the customer reference.
        $this->clock->sleep(3 * 86400);
        $this->send(['id' => 'purchase-1', 'name' => 'purchase', 'customer_ref' => 'customer-42', 'value' => ['amount_minor' => 12000, 'currency' => 'EUR']]);
        $purchase = $this->db->fetchAssociative('SELECT * FROM conversions WHERE external_id = ?', ['purchase-1']);
        self::assertIsArray($purchase);
        self::assertSame('customer_ref', $purchase['attr_via']);
        self::assertSame('paid_search', $purchase['attr_channel']);
        self::assertSame('brand', $purchase['attr_utm_campaign']);

        // An unknown customer stays unattributed but is still counted.
        $this->send(['id' => 'purchase-2', 'name' => 'purchase', 'customer_ref' => 'someone-else']);
        self::assertSame('unattributed', $this->db->fetchOne('SELECT attr_channel FROM conversions WHERE external_id = ?', ['purchase-2']));
        self::assertSame('none', $this->db->fetchOne('SELECT attr_via FROM conversions WHERE external_id = ?', ['purchase-2']));
    }

    public function testOccurredAtBounds(): void
    {
        $this->send(['id' => 'ok', 'name' => 'purchase', 'occurred_at' => $this->clock->now()->modify('-2 days')->format(\DATE_ATOM)]);
        self::assertSame('2026-09-15', $this->db->fetchOne('SELECT local_day FROM conversions WHERE external_id = ?', ['ok']));

        $rejected = $this->json($this->send([
            ['id' => 'future', 'name' => 'purchase', 'occurred_at' => $this->clock->now()->modify('+2 days')->format(\DATE_ATOM)],
            ['id' => 'ancient', 'name' => 'purchase', 'occurred_at' => $this->clock->now()->modify('-60 days')->format(\DATE_ATOM)],
        ]))['rejected'];
        self::assertCount(2, $rejected);
        self::assertStringContainsString('future', (string) $rejected[0]['error']);
        self::assertStringContainsString('30 days', (string) $rejected[1]['error']);
    }

    public function testContentStatsRespectMinimumGroupSize(): void
    {
        $this->site->minGroupSize = 3;
        $this->site->contentContactEvents = ['contact_form'];
        $this->em->flush();
        $url = '/api/v1/server/sites/' . $this->site->publicKey . '/content/author%3A9/stats?days=30';
        $headers = ['Authorization' => 'Bearer ' . $this->secret];

        // Two visitors only: suppressed.
        foreach (['203.0.113.1', '198.51.100.2'] as $ip) {
            $this->collect(Payloads::batch($this->site->publicKey, [['ck' => 'author:9'] + Payloads::pageview('https://www.site.test/post')]), [], $ip);
        }
        $this->service(\Analytics\Reporting\Application\Rollup\RollupRunner::class)->runDirty($this->site->id());
        $data = $this->data($this->request('GET', $url, null, $headers));
        self::assertTrue($data['suppressed']);
        self::assertNull($data['visitors']);

        $this->collect(Payloads::batch($this->site->publicKey, [
            ['ck' => 'author:9'] + Payloads::pageview('https://www.site.test/post'),
            ['ck' => 'author:9'] + Payloads::event('contact_form', [], 'https://www.site.test/post'),
        ]), [], '192.0.2.3');
        $this->service(\Analytics\Reporting\Application\Rollup\RollupRunner::class)->runDirty($this->site->id());
        $this->clearCaches();
        $data = $this->data($this->request('GET', $url, null, $headers));
        self::assertFalse($data['suppressed']);
        self::assertSame(3, $data['visitors']);
        self::assertSame(3, $data['pageviews']);
        self::assertSame(1, $data['contacts']);
        self::assertSame(['direct' => 3], (array) $data['channels']);

        $this->assertProblem($this->request('GET', $url . '&days=0', null, $headers), 422);
    }
}
