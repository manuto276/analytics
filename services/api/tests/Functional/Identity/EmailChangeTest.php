<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Identity;

use Analytics\Identity\Domain\User;
use Analytics\Shared\Mail\Mailer;
use Analytics\Shared\Mail\RecordingMailer;
use Analytics\Shared\Types;
use Analytics\Tests\Support\Factory;
use Analytics\Tests\Support\HttpTestCase;

final class EmailChangeTest extends HttpTestCase
{
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $mailer = $this->container->get(Mailer::class);
        \assert($mailer instanceof RecordingMailer);
        $this->mailer = $mailer;
        $this->mailer->reset();
        $this->mailer->enabled = true;
    }

    protected function tearDown(): void
    {
        $this->mailer->enabled = true;
        parent::tearDown();
    }

    public function testFullChangeConfirmsRevokesOtherSessionsAndNotifiesTheOldAddress(): void
    {
        $user = $this->factory->user(email: 'old@example.com');
        $user->locale = 'it';
        $this->em->flush();
        $this->loginAs($user);
        $otherDevice = $this->cookies;
        $this->loginAs($user);

        $data = $this->data($this->post('/api/v1/auth/email', ['email' => ' New@Example.com ', 'current_password' => Factory::PASSWORD]), 202);
        self::assertSame(['pending_email' => 'new@example.com'], $data);

        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('new@example.com', $mail['to']);
        self::assertStringContainsString('Conferma', $mail['subject'], 'written in the requesting user\'s locale');
        self::assertStringContainsString('<html lang="it">', $mail['html']);
        self::assertStringNotContainsString('<img', $mail['html'], 'no images, no tracking pixels');
        $token = self::tokenFrom($mail['text']);
        self::assertStringContainsString('https://analytics.test/account/email/confirm?token=' . $token, $mail['html']);

        $me = $this->data($this->get('/api/v1/auth/me'));
        self::assertSame('old@example.com', $me['user']['email'], 'nothing changes before the confirmation');
        self::assertSame('new@example.com', $me['user']['pending_email']);

        $stored = $this->db->fetchAssociative('SELECT token_hash, new_email FROM email_changes WHERE user_id = ?', [$user->id()]);
        self::assertIsArray($stored);
        self::assertSame(hash('sha256', $token, true), $stored['token_hash'], 'only the hash is stored');

        // Opened in the same browser: the current session survives, the other device is signed out.
        $this->mailer->reset();
        self::assertSame(['email' => 'new@example.com'], $this->data($this->post('/api/v1/auth/email/confirm', ['token' => $token])));
        $me = $this->data($this->get('/api/v1/auth/me'));
        self::assertSame('new@example.com', $me['user']['email']);
        self::assertNull($me['user']['pending_email']);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('old@example.com', $this->mailer->sent[0]['to'], 'the notice goes to the previous address');
        self::assertStringContainsString('new@example.com', $this->mailer->sent[0]['text']);
        self::assertNotSame('', $this->mailer->sent[0]['html']);

        $this->cookies = $otherDevice;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);

        $audit = $this->db->fetchAssociative("SELECT actor_id, metadata FROM audit_log WHERE action = 'user.email_changed' AND target_id = ?", [(string) $user->id()]);
        self::assertIsArray($audit);
        self::assertSame($user->id(), Types::int($audit['actor_id']));
        self::assertSame(['previous_email' => 'old@example.com', 'revoked_sessions' => 1], json_decode(Types::string($audit['metadata']), true));

        $this->logout();
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => $token]), 410, 'email_change_token_invalid');
        $this->data($this->post('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => Factory::PASSWORD]));
        $this->assertProblem($this->post('/api/v1/auth/login', ['email' => 'old@example.com', 'password' => Factory::PASSWORD]), 401);
    }

    public function testConfirmationFromAnotherDeviceRevokesEverySession(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'elsewhere@example.com', 'current_password' => Factory::PASSWORD]));
        $token = self::tokenFrom($this->mailer->sent[0]['text']);
        $session = $this->cookies;

        $this->logout();
        $this->data($this->post('/api/v1/auth/email/confirm', ['token' => $token]));
        $this->cookies = $session;
        $this->assertProblem($this->get('/api/v1/auth/me'), 401);
    }

    public function testNewRequestReplacesThePreviousOneAndCancelClearsIt(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'first@example.com', 'current_password' => Factory::PASSWORD]));
        $first = self::tokenFrom($this->mailer->sent[0]['text']);
        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'second@example.com', 'current_password' => Factory::PASSWORD]));
        $second = self::tokenFrom($this->mailer->sent[1]['text']);
        self::assertSame('second@example.com', $this->data($this->get('/api/v1/auth/me'))['user']['pending_email']);

        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => $first]), 410, 'email_change_token_invalid');

        $this->assertStatus(204, $this->delete('/api/v1/auth/email'));
        $this->assertStatus(204, $this->delete('/api/v1/auth/email')); // cancelling nothing is fine
        self::assertNull($this->data($this->get('/api/v1/auth/me'))['user']['pending_email']);
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => $second]), 410, 'email_change_token_invalid');
    }

    public function testExpiredTokenIsGoneAndPendingEmailDisappears(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'late@example.com', 'current_password' => Factory::PASSWORD]));
        $token = self::tokenFrom($this->mailer->sent[0]['text']);

        $this->clock->sleep(23 * 3600);
        $this->loginAs($user); // the session itself went idle meanwhile
        self::assertSame('late@example.com', $this->data($this->get('/api/v1/auth/me'))['user']['pending_email']);
        $this->clock->sleep(3600 + 1);
        $this->loginAs($user);
        self::assertNull($this->data($this->get('/api/v1/auth/me'))['user']['pending_email']);
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => $token]), 410, 'email_change_token_invalid');
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => 'not-a-token']), 410, 'email_change_token_invalid');
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', []), 422);
    }

    public function testAddressTakenBeforeTheRequestOrBeforeTheConfirmation(): void
    {
        $this->factory->user(email: 'taken@example.com');
        $user = $this->factory->user();
        $this->loginAs($user);
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'TAKEN@example.com', 'current_password' => Factory::PASSWORD]), 409, 'email_taken');
        self::assertSame([], $this->mailer->sent);

        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'free@example.com', 'current_password' => Factory::PASSWORD]));
        $token = self::tokenFrom($this->mailer->sent[0]['text']);
        $this->factory->user(email: 'free@example.com');
        $this->assertProblem($this->post('/api/v1/auth/email/confirm', ['token' => $token]), 409, 'email_taken');
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotSame('free@example.com', $reloaded->email);
    }

    public function testValidationPasswordAndRateLimit(): void
    {
        $user = $this->factory->user();
        $this->loginAs($user);
        $response = $this->post('/api/v1/auth/email', ['email' => 'not an address']);
        $this->assertProblem($response, 422);
        self::assertSame(['email', 'current_password'], array_keys((array) $this->json($response)['errors']));
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => $user->email, 'current_password' => Factory::PASSWORD]), 422);

        for ($i = 0; $i < 8; ++$i) {
            $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'x@example.com', 'current_password' => 'wrong password']), 422);
        }
        // The budget is shared with POST /auth/password (login_email, 10 per 15 minutes).
        $this->assertProblem($this->post('/api/v1/auth/password', ['current_password' => 'wrong password', 'new_password' => 'another long passphrase']), 422);
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'x@example.com', 'current_password' => Factory::PASSWORD]), 429, 'rate_limited');
        self::assertSame([], $this->mailer->sent);
    }

    public function testRefusedWithoutAMailer(): void
    {
        $this->mailer->enabled = false;
        $this->loginAs($this->factory->user());
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'new@example.com', 'current_password' => Factory::PASSWORD]), 501, 'mailer_disabled');
        self::assertFalse($this->data($this->get('/api/v1/auth/config'))['mailer_enabled']);
    }

    public function testRoutesNeedASessionAndCsrf(): void
    {
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'a@example.com', 'current_password' => 'x']), 401);
        $this->assertProblem($this->delete('/api/v1/auth/email'), 401);
        $this->loginAs($this->factory->user());
        $this->csrfToken = null;
        $this->assertProblem($this->post('/api/v1/auth/email', ['email' => 'a@example.com', 'current_password' => Factory::PASSWORD]), 403, 'csrf_token_invalid');
        $this->assertProblem($this->delete('/api/v1/auth/email'), 403, 'csrf_token_invalid');
    }

    public function testUsersListAndShowCarryPendingEmail(): void
    {
        $admin = $this->factory->admin();
        $this->loginAs($admin);
        $this->assertStatus(202, $this->post('/api/v1/auth/email', ['email' => 'admin-new@example.com', 'current_password' => Factory::PASSWORD]));
        self::assertSame('admin-new@example.com', $this->data($this->get('/api/v1/users/' . $admin->id()))['pending_email']);
        foreach ($this->data($this->get('/api/v1/users')) as $row) {
            self::assertArrayHasKey('pending_email', $row);
        }
    }

    private static function tokenFrom(string $text): string
    {
        self::assertSame(1, preg_match('#/account/email/confirm\?token=([A-Za-z0-9_-]{43})#', $text, $m), $text);

        return $m[1];
    }
}
