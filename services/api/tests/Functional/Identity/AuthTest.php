<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Identity;

use Analytics\Identity\Application\LoginService;
use Analytics\Identity\Application\TotpService;
use Analytics\Shared\Mail\Mailer;
use Analytics\Shared\Mail\RecordingMailer;
use Analytics\Tests\Support\Factory;
use Analytics\Tests\Support\HttpTestCase;

final class AuthTest extends HttpTestCase
{
    public function testLoginSetsHardenedSessionCookieAndReturnsCsrfToken(): void
    {
        $user = $this->factory->user(email: 'alice@example.com');

        $response = $this->post('/api/v1/auth/login', ['email' => 'Alice@Example.com ', 'password' => Factory::PASSWORD]);

        $data = $this->data($response);
        self::assertSame('ok', $data['status']);
        self::assertSame($user->id(), $data['user']['id']);
        self::assertNotEmpty($data['csrf_token']);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith('__Host-an_session=', $cookie);
        self::assertStringContainsString('; Path=/', $cookie);
        self::assertStringContainsString('; HttpOnly', $cookie);
        self::assertStringContainsString('; Secure', $cookie);
        self::assertStringContainsString('; SameSite=Lax', $cookie);
        self::assertStringNotContainsStringIgnoringCase('domain=', $cookie);

        $this->csrfToken = $data['csrf_token'];
        $me = $this->data($this->get('/api/v1/auth/me'));
        self::assertSame('alice@example.com', $me['user']['email']);
        self::assertSame($data['csrf_token'], $me['csrf_token']);
    }

    public function testWrongPasswordAndUnknownEmailLookTheSame(): void
    {
        $this->factory->user(email: 'bob@example.com');

        $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'bob@example.com', 'password' => 'wrong password here']), 401, 'invalid_credentials');
        $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong password here']), 401, 'invalid_credentials');
        self::assertSame([], $this->cookies);
    }

    public function testProgressiveLockoutAfterRepeatedFailures(): void
    {
        $user = $this->factory->user(email: 'carol@example.com');
        for ($i = 0; $i < LoginService::LOCK_THRESHOLD; ++$i) {
            $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'carol@example.com', 'password' => 'nope nope nope']), 401);
        }

        $locked = $this->post('/api/v1/auth/login', ['email' => 'carol@example.com', 'password' => Factory::PASSWORD]);
        $this->assertProblem($locked, 429, 'account_locked');
        self::assertSame('60', $locked->getHeaderLine('Retry-After'));

        $this->clock->sleep(61);
        $this->data($this->post('/api/v1/auth/login', ['email' => 'carol@example.com', 'password' => Factory::PASSWORD]));
        $this->em->refresh($user);
        self::assertSame(0, $user->failedLogins);
    }

    public function testLoginRateLimitPerEmail(): void
    {
        $this->factory->user(email: 'dave@example.com');
        for ($i = 0; $i < 10; ++$i) {
            $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'rate@example.com', 'password' => 'x']), 401);
        }
        $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'rate@example.com', 'password' => 'x']), 429, 'rate_limited');
    }

    public function testValidationErrorsAreFieldLevel(): void
    {
        $response = $this->post('/api/v1/auth/login', ['email' => 'not-an-email']);
        $this->assertProblem($response, 422, 'validation_failed');
        $errors = $this->json($response)['errors'];
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('password', $errors);
    }

    public function testMeRequiresSession(): void
    {
        $this->assertProblem($this->get('/api/v1/auth/me'), 401, 'unauthorized');
        $this->cookies['__Host-an_session'] = 'forged-token';
        $this->assertProblem($this->get('/api/v1/auth/me'), 401, 'unauthorized');
    }

    public function testStateChangingRequestsNeedCsrfToken(): void
    {
        $this->loginAs($this->factory->user());
        $token = $this->csrfToken;
        $this->csrfToken = null;
        $this->assertProblem($this->post('/api/v1/auth/logout'), 403, 'csrf_token_invalid');
        $this->assertProblem($this->post('/api/v1/auth/logout', [], ['X-CSRF-Token' => 'wrong']), 403, 'csrf_token_invalid');
        $this->csrfToken = $token;
        $this->assertStatus(204, $this->post('/api/v1/auth/logout'));
    }

    public function testCrossOriginWritesAreRejected(): void
    {
        $this->factory->user(email: 'erin@example.com');
        $response = $this->post('/api/v1/auth/login', ['email' => 'erin@example.com', 'password' => Factory::PASSWORD], ['Origin' => 'https://evil.example.net']);
        $this->assertProblem($response, 403, 'forbidden');
        $response = $this->post('/api/v1/auth/login', ['email' => 'erin@example.com', 'password' => Factory::PASSWORD], ['Sec-Fetch-Site' => 'cross-site']);
        $this->assertProblem($response, 403, 'forbidden');
    }

    public function testLogoutRevokesSessionAndClearsCookie(): void
    {
        $this->loginAs($this->factory->user());
        $token = $this->cookies['__Host-an_session'];
        $response = $this->post('/api/v1/auth/logout');
        $this->assertStatus(204, $response);
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        $this->cookies['__Host-an_session'] = $token;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testIdleAndAbsoluteExpiry(): void
    {
        $this->loginAs($this->factory->user());
        $this->clock->sleep(11 * 3600);
        $this->data($this->get('/api/v1/auth/me'));
        $this->clock->sleep(11 * 3600); // activity extended the idle window
        $this->data($this->get('/api/v1/auth/me'));
        $this->clock->sleep(12 * 3600 + 1);
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);

        $this->loginAs($this->factory->user());
        for ($day = 0; $day < 30; ++$day) {
            $this->clock->sleep(11 * 3600);
            $this->get('/api/v1/auth/me');
        }
        $this->clock->sleep(3 * 86400);
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testLoginRotatesSessionToken(): void
    {
        $this->factory->user(email: 'frank@example.com');
        $this->post('/api/v1/auth/login', ['email' => 'frank@example.com', 'password' => Factory::PASSWORD]);
        $first = $this->cookies['__Host-an_session'];
        $this->post('/api/v1/auth/login', ['email' => 'frank@example.com', 'password' => Factory::PASSWORD]);
        self::assertNotSame($first, $this->cookies['__Host-an_session']);
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $this->factory->admin();
        $user = $this->factory->user(email: 'gina@example.com');
        $user->status = \Analytics\Identity\Domain\UserStatus::Disabled;
        $this->em->flush();
        $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'gina@example.com', 'password' => Factory::PASSWORD]), 401, 'invalid_credentials');
    }

    public function testTotpEnrolmentAndMfaLogin(): void
    {
        $user = $this->factory->user(email: 'hank@example.com');
        $this->loginAs($user);
        $setup = $this->data($this->post('/api/v1/auth/totp/setup'));
        self::assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        self::assertStringContainsString('<svg', $setup['qr_svg']);

        $totp = $this->service(TotpService::class);
        $this->assertProblem($this->post('/api/v1/auth/totp/confirm', ['code' => '000000']), 422, 'validation_failed');
        $codes = $this->data($this->post('/api/v1/auth/totp/confirm', ['code' => $totp->currentCode($user->id())]))['recovery_codes'];
        self::assertCount(10, $codes);

        $this->logout();
        $this->clock->sleep(60);
        $login = $this->data($this->post('/api/v1/auth/login', ['email' => 'hank@example.com', 'password' => Factory::PASSWORD]));
        self::assertSame(['status' => 'mfa_required'], $login);
        $pendingToken = $this->cookies['__Host-an_session'];

        // A pending session cannot reach the API.
        $this->assertProblem($this->get('/api/v1/auth/me'), 401, 'mfa_required');

        $this->assertProblem($this->post('/api/v1/auth/mfa', ['code' => '123456']), 401, 'invalid_mfa_code');
        $code = $totp->currentCode($user->id());
        $ok = $this->data($this->post('/api/v1/auth/mfa', ['code' => $code]));
        self::assertSame('ok', $ok['status']);
        self::assertNotSame($pendingToken, $this->cookies['__Host-an_session']);
        $this->csrfToken = $ok['csrf_token'];
        self::assertTrue($this->data($this->get('/api/v1/auth/me'))['user']['mfa_enabled']);

        // Replay of the same code is refused.
        $this->logout();
        $this->post('/api/v1/auth/login', ['email' => 'hank@example.com', 'password' => Factory::PASSWORD]);
        $this->assertProblem($this->post('/api/v1/auth/mfa', ['code' => $code]), 401, 'invalid_mfa_code');

        // Recovery codes work once.
        $this->data($this->post('/api/v1/auth/mfa', ['code' => $codes[0]]));
        $this->logout();
        $this->post('/api/v1/auth/login', ['email' => 'hank@example.com', 'password' => Factory::PASSWORD]);
        $this->assertProblem($this->post('/api/v1/auth/mfa', ['code' => $codes[0]]), 401, 'invalid_mfa_code');
    }

    public function testPendingMfaSessionExpires(): void
    {
        $user = $this->factory->user(email: 'ivy@example.com');
        $totp = $this->service(TotpService::class);
        $totp->beginSetup($user);
        $totp->confirm($user, $totp->currentCode($user->id()));
        $this->clock->sleep(60);
        $this->post('/api/v1/auth/login', ['email' => 'ivy@example.com', 'password' => Factory::PASSWORD]);
        $this->clock->sleep(11 * 60);
        $this->assertProblem($this->post('/api/v1/auth/mfa', ['code' => $totp->currentCode($user->id())]), 401, 'unauthorized');
    }

    public function testChangePasswordRevokesOtherSessions(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $otherCookies = $this->cookies;
        $this->loginAs($user);

        $this->assertProblem($this->post('/api/v1/auth/password', ['current_password' => 'wrong', 'new_password' => 'another long passphrase']), 422);
        $this->assertProblem($this->post('/api/v1/auth/password', ['current_password' => Factory::PASSWORD, 'new_password' => 'short']), 422);
        $this->assertStatus(204, $this->post('/api/v1/auth/password', ['current_password' => Factory::PASSWORD, 'new_password' => 'another long passphrase']));
        $this->data($this->get('/api/v1/auth/me'));

        $this->cookies = $otherCookies;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testSessionListAndRevoke(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $first = $this->cookies;
        $this->loginAs($user);
        $sessions = $this->data($this->get('/api/v1/auth/sessions'));
        self::assertCount(2, $sessions);
        $other = array_values(array_filter($sessions, static fn(array $s): bool => !$s['current']))[0];
        $this->assertStatus(204, $this->delete('/api/v1/auth/sessions/' . $other['id']));
        $this->assertProblem($this->delete('/api/v1/auth/sessions/' . $other['id']), 404);

        $this->cookies = $first;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testForgotAndResetPassword(): void
    {
        $mailer = $this->container->get(Mailer::class);
        \assert($mailer instanceof RecordingMailer);
        $mailer->reset();
        $this->factory->user(email: 'judy@example.com');

        $this->assertStatus(202, $this->post('/api/v1/auth/password/forgot', ['email' => 'judy@example.com']));
        $this->assertStatus(202, $this->post('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']));
        self::assertCount(1, $mailer->sent);
        self::assertSame(1, preg_match('#/password/reset/([A-Za-z0-9_-]{43})#', $mailer->sent[0]['text'], $m));

        $this->assertStatus(204, $this->post('/api/v1/auth/password/reset', ['token' => $m[1], 'password' => 'brand new passphrase']));
        $this->assertProblem($this->post('/api/v1/auth/password/reset', ['token' => $m[1], 'password' => 'brand new passphrase']), 410, 'reset_token_invalid');
        $this->data($this->post('/api/v1/auth/login', ['email' => 'judy@example.com', 'password' => 'brand new passphrase']));
    }

    public function testAuthConfigIsPublic(): void
    {
        $data = $this->data($this->get('/api/v1/auth/config'));
        self::assertTrue($data['mailer_enabled']);
        self::assertSame(['en', 'it'], $data['locales']);
    }
}
