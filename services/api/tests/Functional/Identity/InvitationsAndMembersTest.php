<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Identity;

use Analytics\Identity\Domain\SiteRole;
use Analytics\Tests\Support\HttpTestCase;

final class InvitationsAndMembersTest extends HttpTestCase
{
    public function testAdminInvitesViewerWhoAcceptsAndCannotManageSettings(): void
    {
        $admin = $this->factory->admin();
        $site = $this->factory->site();
        $this->loginAs($admin);

        $created = $this->data($this->post('/api/v1/invitations', [
            'email' => 'viewer@example.com',
            'global_role' => 'member',
            'site_roles' => [['site_id' => $site->id(), 'role' => 'viewer']],
        ]), 201);
        self::assertSame('pending', $created['status']);
        self::assertSame(1, preg_match('#^https://analytics\.test/invite/([A-Za-z0-9_-]{43})$#', $created['link'], $m));
        $token = $m[1];

        $this->assertProblem($this->post('/api/v1/invitations', ['email' => $admin->email]), 409, 'email_taken');

        $this->logout();
        $shown = $this->data($this->get('/api/v1/invitations/' . $token));
        self::assertSame('viewer@example.com', $shown['email']);
        self::assertSame([['name' => $site->name, 'role' => 'viewer']], $shown['sites']);

        $this->assertProblem($this->post('/api/v1/invitations/' . $token . '/accept', ['display_name' => 'Vera', 'password' => 'short']), 422);
        $accepted = $this->data($this->post('/api/v1/invitations/' . $token . '/accept', ['display_name' => 'Vera', 'password' => 'a very long passphrase', 'locale' => 'it']), 201);
        self::assertSame('ok', $accepted['status']);
        $this->csrfToken = $accepted['csrf_token'];

        $me = $this->data($this->get('/api/v1/auth/me'));
        self::assertSame('it', $me['user']['locale']);
        self::assertSame([['site_id' => $site->id(), 'role' => 'viewer']], $me['user']['site_roles']);

        $sites = $this->data($this->get('/api/v1/sites'));
        self::assertCount(1, $sites);
        self::assertSame('viewer', $sites[0]['role']);
        $this->assertProblem($this->patch('/api/v1/sites/' . $site->id(), ['name' => 'Hacked']), 403, 'forbidden');

        $this->logout();
        $this->assertProblem($this->get('/api/v1/invitations/' . $token), 410, 'invitation_accepted');
    }

    public function testExpiredAndRevokedInvitations(): void
    {
        $this->loginAs($this->factory->admin());
        $first = $this->data($this->post('/api/v1/invitations', ['email' => 'late@example.com']), 201);
        $second = $this->data($this->post('/api/v1/invitations', ['email' => 'revoked@example.com']), 201);
        $revoked = $this->data($this->delete('/api/v1/invitations/' . $second['id']));
        self::assertSame('revoked', $revoked['status']);

        $this->clock->sleep(8 * 86400);
        $this->logout();
        $this->assertProblem($this->get('/api/v1/invitations/' . basename($first['link'])), 410, 'invitation_expired');
        $this->assertProblem($this->get('/api/v1/invitations/' . basename($second['link'])), 410, 'invitation_revoked');
        $this->assertProblem($this->get('/api/v1/invitations/' . str_repeat('a', 43)), 404, 'not_found');
    }

    public function testSiteAdminManagesMembers(): void
    {
        $site = $this->factory->site();
        $siteAdmin = $this->factory->user();
        $this->factory->grant($siteAdmin, $site, SiteRole::Admin);
        $other = $this->factory->user(email: 'other@example.com');
        $this->loginAs($siteAdmin);

        $members = $this->data($this->post('/api/v1/sites/' . $site->id() . '/members', ['email' => 'other@example.com', 'role' => 'viewer']));
        self::assertContains('other@example.com', array_column($members, 'email'));
        $members = $this->data($this->put('/api/v1/sites/' . $site->id() . '/members/' . $other->id(), ['role' => 'admin']));
        $row = array_values(array_filter($members, static fn(array $m): bool => $m['user_id'] === $other->id()))[0];
        self::assertSame('admin', $row['role']);

        $this->assertProblem($this->put('/api/v1/sites/' . $site->id() . '/members/' . $siteAdmin->id(), ['role' => 'viewer']), 409, 'cannot_demote_self');
        $this->assertStatus(204, $this->delete('/api/v1/sites/' . $site->id() . '/members/' . $other->id()));

        $invite = $this->data($this->post('/api/v1/sites/' . $site->id() . '/invitations', ['email' => 'new@example.com', 'role' => 'viewer']), 201);
        self::assertSame([['site_id' => $site->id(), 'role' => 'viewer']], $invite['site_roles']);
        self::assertSame('member', $invite['global_role']);
    }

    public function testUsersAdministration(): void
    {
        $admin = $this->factory->admin();
        $user = $this->factory->user();
        $this->loginAs($admin);

        self::assertGreaterThanOrEqual(2, \count($this->data($this->get('/api/v1/users'))));
        $updated = $this->data($this->patch('/api/v1/users/' . $user->id(), ['global_role' => 'admin', 'display_name' => 'Promoted']));
        self::assertSame('admin', $updated['global_role']);
        self::assertSame('Promoted', $updated['display_name']);

        $this->assertProblem($this->patch('/api/v1/users/' . $admin->id(), ['status' => 'disabled']), 409, 'cannot_disable_self');
        $disabled = $this->data($this->patch('/api/v1/users/' . $user->id(), ['status' => 'disabled']));
        self::assertSame('disabled', $disabled['status']);
        $this->assertProblem($this->patch('/api/v1/users/' . $user->id(), ['global_role' => 'boss']), 422);
        $this->assertProblem($this->get('/api/v1/users/999999'), 404);
    }

    public function testLastAdminCannotBeDemoted(): void
    {
        $this->db->executeStatement("UPDATE users SET global_role = 'member'");
        $admin = $this->factory->admin();
        $this->loginAs($admin);
        $this->assertProblem($this->patch('/api/v1/users/' . $admin->id(), ['global_role' => 'member']), 409, 'last_admin');
    }
}
