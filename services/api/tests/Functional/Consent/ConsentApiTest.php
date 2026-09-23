<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Consent;

use Analytics\Consent\Application\ConsentService;
use Analytics\Consent\Application\ContrastChecker;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Tests\Support\HttpTestCase;

final class ConsentApiTest extends HttpTestCase
{
    public function testDraftPublishVersioningAndHistory(): void
    {
        $site = $this->factory->site();
        $admin = $this->factory->user();
        $this->factory->grant($admin, $site, SiteRole::Admin);
        $this->loginAs($admin);
        $base = '/api/v1/sites/' . $site->id() . '/consent';

        $initial = $this->data($this->get($base));
        self::assertNull($initial['published']);
        self::assertNull($initial['draft']);

        $this->assertProblem($this->post($base . '/publish', ['material_change' => false]), 409, 'no_draft');

        $draft = $this->data($this->put($base . '/draft', ConsentService::defaults()));
        self::assertSame('draft', $draft['status']);
        self::assertSame(1, $draft['revision']);

        $published = $this->data($this->post($base . '/publish', ['material_change' => false]));
        self::assertSame('published', $published['status']);
        self::assertSame(1, $published['consent_version'], 'first publication is version 1');

        $changed = ConsentService::defaults();
        $changed['texts']['en']['body'] = 'Wording tweak.';
        $this->data($this->put($base . '/draft', $changed));
        $v1 = $this->data($this->post($base . '/publish', ['material_change' => false]));
        self::assertSame(2, $v1['revision']);
        self::assertSame(1, $v1['consent_version'], 'non-material change keeps the version');

        $this->data($this->put($base . '/draft', $changed));
        $v2 = $this->data($this->post($base . '/publish', ['material_change' => true]));
        self::assertSame(3, $v2['revision']);
        self::assertSame(2, $v2['consent_version']);

        $history = $this->data($this->get($base . '/history'));
        self::assertSame([3, 2, 1], array_column($history, 'revision'));
        self::assertSame(['published', 'archived', 'archived'], array_column($history, 'status'));
    }

    public function testValidationAndContrast(): void
    {
        $site = $this->factory->site();
        $this->loginAs($this->factory->admin());
        $bad = ConsentService::defaults();
        $bad['theme'] = ['colors' => ['text' => '#eeeeee', 'accentText' => '#1d4ed9']];
        $bad['texts']['en']['accept'] = '';
        $bad['policy_urls']['en'] = 'javascript:alert(1)';
        $bad['default_locale'] = 'fr';
        $response = $this->put('/api/v1/sites/' . $site->id() . '/consent/draft', $bad);
        $this->assertProblem($response, 422, 'validation_failed');
        $errors = $this->json($response)['errors'];
        foreach (['theme.colors.text', 'theme.colors.accentText', 'texts.en.accept', 'policy_urls.en', 'default_locale'] as $field) {
            self::assertArrayHasKey($field, $errors);
        }

        // A v1 theme keeps the v1 contrast rules.
        $v1 = ['theme' => ['bg' => '#ffffff', 'fg' => '#eeeeee', 'ac' => '#1d4ed8', 'acf' => '#1d4ed9', 'rad' => 8, 'pos' => 'bottom']] + ConsentService::defaults();
        $response = $this->put('/api/v1/sites/' . $site->id() . '/consent/draft', $v1);
        $this->assertProblem($response, 422, 'validation_failed');
        self::assertSame(['theme.fg', 'theme.acf'], array_keys($this->json($response)['errors']));

        self::assertSame(21.0, ContrastChecker::ratio('#000000', '#ffffff'));
        self::assertGreaterThan(4.5, ContrastChecker::ratio('#ffffff', '#1d4ed8'));
        self::assertLessThan(4.5, ContrastChecker::ratio('#777777', '#888888'));
    }

    public function testConsentReceiptsProveOneVisitorsChoice(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true, 'consentReceiptsEnabled' => true], ['www.site.test']);
        $this->loginAs($this->factory->admin());
        $base = '/api/v1/sites/' . $site->id() . '/consent/receipts';
        $vid = \Analytics\Tests\Support\Payloads::id22();

        $before = $this->data($this->get($base . '?visitor_id=' . $vid));
        self::assertCount(0, $before, 'no decision recorded yet');

        $this->collect(\Analytics\Tests\Support\Payloads::batch($site->publicKey, [
            \Analytics\Tests\Support\Payloads::consentUpgrade('https://www.site.test/'),
        ], 'c', ['vid' => $vid, 'sid' => \Analytics\Tests\Support\Payloads::id22(), 'cv' => 2]));

        $receipts = $this->data($this->get($base . '?visitor_id=' . $vid));
        self::assertCount(1, $receipts);
        self::assertSame(['consent_version' => 2, 'decision' => 'accept', 'decided_at' => '2026-09-17T10:00:00+00:00'], $receipts[0]);
        $otherVisitor = $this->data($this->get($base . '?visitor_id=' . \Analytics\Tests\Support\Payloads::id22()));
        self::assertCount(0, $otherVisitor, 'other visitors are not exposed');

        $this->assertProblem($this->get($base . '?visitor_id=nope'), 422);
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'consent.receipts_read'"), 'every lookup is audited');

        $without = $this->factory->site(['cookieLevelEnabled' => true], ['other.test']);
        $this->assertProblem($this->get('/api/v1/sites/' . $without->id() . '/consent/receipts?visitor_id=' . $vid), 409, 'receipts_disabled');
    }

    public function testViewerCanReadButNotEdit(): void
    {
        $site = $this->factory->site();
        $viewer = $this->factory->user();
        $this->factory->grant($viewer, $site, SiteRole::Viewer);
        $this->loginAs($viewer);
        $this->data($this->get('/api/v1/sites/' . $site->id() . '/consent'));
        $this->assertProblem($this->put('/api/v1/sites/' . $site->id() . '/consent/draft', ConsentService::defaults()), 403);
    }
}
