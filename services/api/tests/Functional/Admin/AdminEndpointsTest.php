<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Admin;

use Analytics\Consent\Application\ConsentService;
use Analytics\Shared\Validation\Input;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class AdminEndpointsTest extends HttpTestCase
{
    public function testAuditLogRecordsActionsAndPages(): void
    {
        $admin = $this->factory->admin();
        $this->loginAs($admin);
        $site = $this->data($this->post('/api/v1/sites', ['name' => 'Example', 'domains' => [['host' => 'www.example.com', 'include_subdomains' => true]]]), 201);
        $this->patch('/api/v1/sites/' . $site['id'], ['timezone' => 'Europe/Rome']);
        $this->post('/api/v1/sites/' . $site['id'] . '/api-keys', ['name' => 'backend', 'scopes' => ['conversions:write']]);

        $response = $this->get('/api/v1/admin/audit-log?limit=2');
        $body = $this->json($response);
        self::assertCount(2, $body['data']);
        self::assertSame(['api_key.created', 'site.updated'], array_column($body['data'], 'action'));
        self::assertSame($admin->email, $body['data'][0]['actor_email']);
        self::assertSame('203.0.113.0/24', $body['data'][0]['ip_prefix']);
        self::assertIsString($body['meta']['next_cursor']);

        $next = $this->json($this->get('/api/v1/admin/audit-log?limit=2&cursor=' . $body['meta']['next_cursor']));
        self::assertSame('site.created', $next['data'][0]['action']);

        $filtered = $this->json($this->get('/api/v1/admin/audit-log?action=site.created'));
        self::assertCount(1, $filtered['data']);
        self::assertSame(['name' => 'Example'], $filtered['data'][0]['metadata']);

        $bySite = $this->json($this->get('/api/v1/admin/audit-log?site_id=' . $site['id']));
        self::assertGreaterThanOrEqual(3, \count($bySite['data']));

        $this->assertProblem($this->get('/api/v1/admin/audit-log?site_id=abc'), 422);
        $this->assertProblem($this->get('/api/v1/admin/audit-log?action=NOT VALID'), 422);

        $this->loginAs($this->factory->user());
        $this->assertProblem($this->get('/api/v1/admin/audit-log'), 403);
    }

    public function testJobsStatusReportsLagDraftsAndRuns(): void
    {
        $admin = $this->factory->admin();
        $site = $this->factory->site([], ['www.site.test']);
        $this->loginAs($admin);

        $consent = $this->service(ConsentService::class);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/')]));
        $this->clock->sleep(3600);

        $data = $this->data($this->get('/api/v1/admin/jobs'));
        self::assertSame(60, $data['rollup_lag_minutes'], 'an hour of unprocessed dirty days');
        self::assertSame(1, $data['dirty_days']);
        self::assertNull($data['geo_db_age_days'], 'no geo database in tests');
        self::assertSame([['site_id' => $site->id(), 'site_name' => $site->name]], $data['pending_consent_drafts']);
        $jobs = array_column($data['jobs'], 'job');
        self::assertContains('rollup:run', $jobs);
        self::assertContains('retention:purge', $jobs);
        foreach ($data['jobs'] as $job) {
            self::assertNull($job['last_status'], 'no job has run yet in this test');
        }

        $this->service(\Analytics\Reporting\Application\Rollup\RollupRunner::class)->runDirty($site->id());
        $this->clearCaches();
        $after = $this->data($this->get('/api/v1/admin/jobs'));
        self::assertSame(0, $after['rollup_lag_minutes']);
        self::assertSame(0, $after['dirty_days']);

        $this->loginAs($this->factory->user());
        $this->assertProblem($this->get('/api/v1/admin/jobs'), 403);
    }
}
