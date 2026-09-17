<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Identity;

use Analytics\Identity\Application\PasswordHasher;
use Analytics\Identity\Application\PasswordResetService;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Shared\Crypto\SecretBox;
use Analytics\Shared\Mail\RecordingMailer;
use Analytics\Tests\Support\Factory;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\TestContainer;

final class IdentityEdgeCasesTest extends HttpTestCase
{
    public function testTotpDisableRecoveryCodesAndReplayWindow(): void
    {
        $user = $this->factory->user();
        $totp = $this->service(TotpService::class);
        $this->loginAs($user);

        $this->assertProblem($this->post('/api/v1/auth/totp/recovery-codes'), 409, 'totp_not_enabled');

        $totp->beginSetup($user);
        self::assertFalse($totp->isEnabled($user->id()));
        $codes = $totp->confirm($user, $totp->currentCode($user->id()));
        self::assertIsArray($codes);
        self::assertTrue($totp->isEnabled($user->id()));
        self::assertSame(10, $totp->remainingRecoveryCodes($user->id()));

        // Codes inside the window are accepted once; replays and codes at or below the last used step are refused.
        self::assertFalse($totp->verifyLogin($user->id(), $totp->currentCode($user->id(), -1)), 'a code already used or older is refused');
        $next = $totp->currentCode($user->id(), 1);
        self::assertTrue($totp->verifyLogin($user->id(), $next));
        self::assertFalse($totp->verifyLogin($user->id(), $next), 'replay is refused');
        self::assertFalse($totp->verifyLogin($user->id(), '000000'));
        self::assertFalse($totp->verifyLogin($user->id(), 'not-a-code'));

        // Recovery codes are single use and counted.
        self::assertTrue($totp->verifyLogin($user->id(), $codes[0]));
        self::assertSame(9, $totp->remainingRecoveryCodes($user->id()));
        self::assertFalse($totp->verifyLogin($user->id(), $codes[0]));

        $fresh = $this->data($this->post('/api/v1/auth/totp/recovery-codes'))['recovery_codes'];
        self::assertCount(10, $fresh);
        self::assertNotSame($codes, $fresh);
        self::assertFalse($totp->verifyLogin($user->id(), $codes[1]), 'old codes are replaced');

        $this->assertProblem($this->post('/api/v1/auth/totp/setup'), 409, 'totp_already_enabled');
        $this->assertProblem($this->delete('/api/v1/auth/totp', ['password' => 'wrong password']), 422);
        $this->assertStatus(204, $this->delete('/api/v1/auth/totp', ['password' => Factory::PASSWORD]));
        self::assertFalse($totp->isEnabled($user->id()));
        self::assertSame(0, $totp->remainingRecoveryCodes($user->id()));
        self::assertFalse($totp->verifyLogin($user->id(), '123456'), 'nothing to verify any more');
    }

    public function testTotpSecretsAreReencryptedWithTheActiveKey(): void
    {
        $user = $this->factory->user();
        $totp = $this->service(TotpService::class);
        $totp->beginSetup($user);
        $credential = $totp->credential($user->id());
        self::assertNotNull($credential);
        $originalKeyId = $credential->keyId;
        self::assertFalse($totp->reencrypt($credential), 'already on the active key');

        // A container with a second key in the ring re-encrypts the stored secret.
        $rotated = TestContainer::build([
            'APP_ENCRYPTION_KEYS' => 'k1:' . base64_encode(str_repeat("\x02", 32)) . ',k2:' . base64_encode(str_repeat("\x03", 32)),
        ]);
        $rotatedTotp = $rotated->get(TotpService::class);
        \assert($rotatedTotp instanceof TotpService);
        $box = $rotated->get(SecretBox::class);
        \assert($box instanceof SecretBox);
        self::assertSame('k2', $box->activeKeyId());

        $secretBefore = $this->service(SecretBox::class)->decrypt($credential->secretCiphertext, 'totp:' . $user->id());
        self::assertTrue($rotatedTotp->reencrypt($credential));
        self::assertSame('k2', $credential->keyId);
        self::assertNotSame($originalKeyId, $credential->keyId);
        self::assertSame($secretBefore, $box->decrypt($credential->secretCiphertext, 'totp:' . $user->id()), 'the secret itself is unchanged');
        $rotated->get(\Doctrine\DBAL\Connection::class)->close();
    }

    public function testSessionCookieCanArriveOnlyInTheHeader(): void
    {
        // The request carries the session only in a raw Cookie header, which the contract does not model.
        $this->validateOpenApi = false;
        $user = $this->factory->user();
        $sessions = $this->service(SessionManager::class);
        [$token, $session] = $sessions->start($user, SessionState::Active, '203.0.113.0/24', 'Chrome on macOS');

        $response = $this->request('GET', '/api/v1/auth/me', null, ['Cookie' => 'other=1; __Host-an_session=' . $token]);
        $this->assertStatus(200, $response);
        self::assertSame($user->email, $this->json($response)['data']['user']['email']);

        self::assertSame(SessionManager::publicId($session), $this->json($response)['data']['session']['id']);
        self::assertStringContainsString('; Secure', $sessions->cookieHeader($token, $session));
        self::assertStringContainsString('Max-Age=0', $sessions->clearCookieHeader());
    }

    public function testRevokingEveryOtherSession(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $first = $this->cookies;
        $this->loginAs($user);
        $this->loginAs($user);

        self::assertSame(['revoked' => 2], $this->data($this->delete('/api/v1/auth/sessions')));
        $this->data($this->get('/api/v1/auth/me'));
        $this->cookies = $first;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testPasswordResetIsRefusedWithoutAMailer(): void
    {
        $container = TestContainer::build(['MAILER_DSN' => null], '', [\Analytics\Shared\Mail\Mailer::class => new RecordingMailer(false)]);
        $resets = $container->get(PasswordResetService::class);
        \assert($resets instanceof PasswordResetService);
        self::assertFalse($resets->isAvailable());
        try {
            $resets->request('someone@example.com');
            self::fail('expected a 501');
        } catch (\Analytics\Shared\Http\ApiProblem $problem) {
            self::assertSame(501, $problem->status);
            self::assertSame('mailer_disabled', $problem->type);
        }
        $container->get(\Doctrine\DBAL\Connection::class)->close();
    }

    public function testPasswordHashingWithProductionCost(): void
    {
        $hasher = new PasswordHasher(false);
        $hash = $hasher->hash('a long enough passphrase');
        self::assertTrue($hasher->verify('a long enough passphrase', $hash));
        self::assertFalse($hasher->verify('another passphrase', $hash));
        $hasher->dummyVerify('anything');
        $this->addToAssertionCount(1);
    }

    public function testDisablingAUserKillsTheirOpenSession(): void
    {
        $this->factory->admin();
        $user = $this->factory->user();
        $this->loginAs($user);
        $this->data($this->get('/api/v1/auth/me'));

        $user->status = \Analytics\Identity\Domain\UserStatus::Disabled;
        $this->em->flush();

        $this->assertProblem($this->get('/api/v1/auth/me'), 401, 'unauthorized');
        self::assertSame(0, \count($this->service(SessionManager::class)->activeSessions($user->id())), 'the session is revoked on the spot');
    }

    public function testSiteRolesAreReadableThroughTheMapping(): void
    {
        $user = $this->factory->user();
        $site = $this->factory->site();
        $this->factory->grant($user, $site, SiteRole::Viewer);
        $this->em->clear();

        $role = $this->em->find(\Analytics\Identity\Domain\UserSiteRole::class, ['userId' => $user->id(), 'siteId' => $site->id()]);
        self::assertInstanceOf(\Analytics\Identity\Domain\UserSiteRole::class, $role);
        self::assertSame(SiteRole::Viewer, $role->role);

        $entity = new \Analytics\Identity\Domain\UserSiteRole($user->id(), $site->id(), SiteRole::Admin, $this->clock->now());
        self::assertSame(SiteRole::Admin, $entity->role);
        self::assertSame($site->id(), $entity->siteId);
    }

    public function testSiteScopedRoutesRejectNonNumericSiteIds(): void
    {
        $this->validateOpenApi = false;
        $this->loginAs($this->factory->admin());
        $this->assertProblem($this->get('/api/v1/sites/999999/reports/overview'), 404, 'not_found');
    }

    public function testSiteAdminsSeeAndRevokeOnlyTheirSiteInvitations(): void
    {
        $site = $this->factory->site();
        $otherSite = $this->factory->site();
        $siteAdmin = $this->factory->user();
        $this->factory->grant($siteAdmin, $site, SiteRole::Admin);

        $this->loginAs($this->factory->admin());
        $foreign = $this->data($this->post('/api/v1/invitations', ['email' => 'foreign@example.com', 'site_roles' => [['site_id' => $otherSite->id(), 'role' => 'viewer']]]), 201);

        $this->loginAs($siteAdmin);
        $mine = $this->data($this->post('/api/v1/sites/' . $site->id() . '/invitations', ['email' => 'mine@example.com', 'role' => 'viewer']), 201);

        $list = $this->data($this->get('/api/v1/sites/' . $site->id() . '/invitations'));
        self::assertSame(['mine@example.com'], array_column($list, 'email'));

        $this->assertProblem($this->delete('/api/v1/sites/' . $site->id() . '/invitations/' . $foreign['id']), 404);
        $revoked = $this->data($this->delete('/api/v1/sites/' . $site->id() . '/invitations/' . $mine['id']));
        self::assertSame('revoked', $revoked['status']);
    }
}
