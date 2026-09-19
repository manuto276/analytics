<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\EmailChangeService;
use Analytics\Identity\Application\LoginResult;
use Analytics\Identity\Application\LoginService;
use Analytics\Identity\Application\PasswordHasher;
use Analytics\Identity\Application\PasswordResetService;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\SessionState;
use Analytics\Kernel\BuildInfo;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Kernel\Settings;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthController
{
    public function __construct(
        private JsonResponder $responder,
        private LoginService $login,
        private SessionManager $sessions,
        private UserService $users,
        private TotpService $totp,
        private PasswordHasher $hasher,
        private PasswordResetService $resets,
        private EmailChangeService $emailChanges,
        private AuditLogger $audit,
        private RateLimiter $rateLimiter,
        private Settings $settings,
        private BuildInfo $build,
    ) {}

    public function config(): ResponseInterface
    {
        return $this->responder->data([
            'version' => $this->build->version,
            'commit' => $this->build->commit,
            'source_url' => $this->settings->sourceUrl,
            'mailer_enabled' => $this->resets->isAvailable(),
            'locales' => UserService::LOCALES,
            'app_url' => $this->settings->appUrl,
        ]);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $password = $input->secret('password');
        $input->assertValid();

        $result = $this->login->login($email, $password, RequestContext::ipPrefixString($request), RequestContext::uaSummary($request));

        return $this->sessionResponse($result);
    }

    public function mfa(ServerRequestInterface $request): ResponseInterface
    {
        $token = SessionMiddleware::token($request, $this->sessions->cookieName());
        $pending = $token === null ? null : $this->sessions->resolve($token);
        if ($pending === null || $pending->state !== SessionState::PendingMfa) {
            throw ApiProblem::unauthorized('No pending sign-in. Sign in again.');
        }
        $input = RequestContext::body($request);
        $code = $input->string('code', 16, 6);
        $input->assertValid();

        return $this->sessionResponse($this->login->completeMfa($pending, $code, RequestContext::ipPrefixString($request), RequestContext::uaSummary($request)));
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $session = RequestContext::session($request);
        $this->sessions->revoke($session);
        $this->audit->log('auth.logout', RequestContext::actor($request), null, 'user', $session->userId);

        return $this->responder->noContent()->withAddedHeader('Set-Cookie', $this->sessions->clearCookieHeader());
    }

    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $session = RequestContext::session($request);

        return $this->responder->data([
            'user' => UserService::toArray($user, $this->totp->isEnabled($user->id()), $this->users->siteRoles($user->id()), $this->users->pendingEmail($user->id())),
            'csrf_token' => $session->csrfSecret,
            'session' => ['id' => SessionManager::publicId($session), 'absolute_expires_at' => $session->absoluteExpiresAt->format(\DATE_ATOM)],
        ]);
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = RequestContext::body($request);
        if ($input->has('display_name')) {
            $user->displayName = $input->string('display_name', 120);
        }
        if ($input->has('locale')) {
            $user->locale = $input->choice('locale', UserService::LOCALES);
        }
        $input->assertValid();
        $this->users->save($user);

        return $this->me($request);
    }

    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = RequestContext::body($request);
        $current = $input->secret('current_password');
        $new = $input->secret('new_password');
        $input->assertValid();
        $this->rateLimiter->enforce('login_email', self::passwordCheckKey($user->id()));
        if (!$this->hasher->verify($current, $user->passwordHash)) {
            throw ApiProblem::validation(['current_password' => ['Current password is incorrect.']]);
        }
        $this->users->setPassword($user, $new);
        $revoked = $this->sessions->revokeAll($user->id(), RequestContext::session($request));
        $this->audit->log('user.password_changed', RequestContext::actor($request), null, 'user', $user->id(), ['revoked_sessions' => $revoked]);

        return $this->responder->noContent();
    }

    /**
     * Starts an email change. The current-password check shares the `login_email` budget with
     * POST /auth/password (same key), so a stolen session cannot double the guesses by alternating
     * endpoints; the same budget also caps how many confirmation mails one account can trigger.
     */
    public function requestEmailChange(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $current = $input->secret('current_password');
        $input->assertValid();
        $this->emailChanges->assertAvailable();
        $this->rateLimiter->enforce('login_email', self::passwordCheckKey($user->id()));
        if (!$this->hasher->verify($current, $user->passwordHash)) {
            throw ApiProblem::validation(['current_password' => ['Current password is incorrect.']]);
        }
        $pending = $this->emailChanges->request($user, $email);
        $this->audit->log('user.email_change_requested', RequestContext::actor($request), null, 'user', $user->id());

        return $this->responder->json(['data' => ['pending_email' => $pending]], 202);
    }

    public function cancelEmailChange(ServerRequestInterface $request): ResponseInterface
    {
        $this->emailChanges->cancel(RequestContext::user($request));

        return $this->responder->noContent();
    }

    /**
     * Public: the link may be opened on a device without a session. If the request does carry an
     * active session of the same user, that one survives; every other session is revoked.
     */
    public function confirmEmailChange(ServerRequestInterface $request): ResponseInterface
    {
        $ipPrefix = RequestContext::ipPrefixString($request);
        $this->rateLimiter->enforce('public', 'email-confirm:' . ($ipPrefix ?? '-'));
        $input = RequestContext::body($request);
        $token = $input->string('token', 64);
        $input->assertValid();

        $cookie = SessionMiddleware::token($request, $this->sessions->cookieName());
        $session = $cookie === null ? null : $this->sessions->resolve($cookie);
        $keep = $session !== null && $session->state === SessionState::Active ? $session : null;

        $result = $this->emailChanges->confirm($token, $keep);
        $user = $result['user'];
        $this->audit->log('user.email_changed', new Actor('user', $user->id(), $ipPrefix), null, 'user', $user->id(), [
            'previous_email' => $result['old_email'],
            'revoked_sessions' => $result['revoked_sessions'],
        ]);

        return $this->responder->data(['email' => $user->email]);
    }

    public function forgotPassword(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $input->assertValid();
        $this->rateLimiter->enforce('login_ip', 'forgot:' . (RequestContext::ipPrefixString($request) ?? '-'));
        $this->resets->request($email);

        return $this->responder->json(['data' => ['status' => 'sent_if_account_exists']], 202);
    }

    public function resetPassword(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestContext::body($request);
        $token = $input->string('token', 64);
        $password = $input->secret('password');
        $input->assertValid();
        $this->resets->reset($token, $password);

        return $this->responder->noContent();
    }

    public function totpSetup(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        if ($this->totp->isEnabled($user->id())) {
            throw ApiProblem::conflict('totp_already_enabled', 'Two-factor authentication is already enabled. Disable it first.');
        }

        return $this->responder->data($this->totp->beginSetup($user));
    }

    public function totpConfirm(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = RequestContext::body($request);
        $code = $input->string('code', 6, 6, '/^\d{6}$/');
        $input->assertValid();
        $codes = $this->totp->confirm($user, $code);
        if ($codes === null) {
            throw ApiProblem::validation(['code' => ['The code is not valid.']]);
        }
        $this->sessions->revokeAll($user->id(), RequestContext::session($request));
        $this->audit->log('user.totp_enabled', RequestContext::actor($request), null, 'user', $user->id());

        return $this->responder->data(['recovery_codes' => $codes]);
    }

    public function totpDisable(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = RequestContext::body($request);
        $password = $input->secret('password');
        $input->assertValid();
        if (!$this->hasher->verify($password, $user->passwordHash)) {
            throw ApiProblem::validation(['password' => ['Password is incorrect.']]);
        }
        $this->totp->disable($user->id());
        $this->audit->log('user.totp_disabled', RequestContext::actor($request), null, 'user', $user->id());

        return $this->responder->noContent();
    }

    public function recoveryCodes(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        if (!$this->totp->isEnabled($user->id())) {
            throw ApiProblem::conflict('totp_not_enabled', 'Two-factor authentication is not enabled.');
        }
        $codes = $this->totp->regenerateRecoveryCodes($user->id());
        $this->audit->log('user.recovery_codes_regenerated', RequestContext::actor($request), null, 'user', $user->id());

        return $this->responder->data(['recovery_codes' => $codes]);
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $current = RequestContext::session($request);
        $list = [];
        foreach ($this->sessions->activeSessions(RequestContext::user($request)->id()) as $session) {
            $list[] = [
                'id' => SessionManager::publicId($session),
                'current' => $session->id === $current->id,
                'created_at' => $session->createdAt->format(\DATE_ATOM),
                'last_seen_at' => $session->lastSeenAt->format(\DATE_ATOM),
                'ua_summary' => $session->uaSummary,
                'ip_prefix' => $session->ipPrefix,
            ];
        }

        return $this->responder->data($list);
    }

    public function revokeSession(ServerRequestInterface $request, string $sessionId): ResponseInterface
    {
        $user = RequestContext::user($request);
        foreach ($this->sessions->activeSessions($user->id()) as $session) {
            if (hash_equals(SessionManager::publicId($session), $sessionId)) {
                $this->sessions->revoke($session);
                $this->audit->log('auth.session_revoked', RequestContext::actor($request), null, 'user', $user->id());

                return $this->responder->noContent();
            }
        }

        throw ApiProblem::notFound('Session not found.');
    }

    public function revokeOtherSessions(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $count = $this->sessions->revokeAll($user->id(), RequestContext::session($request));

        return $this->responder->data(['revoked' => $count]);
    }

    /** Rate-limit key for "prove you know the current password" checks of a signed-in user. */
    private static function passwordCheckKey(int $userId): string
    {
        return 'pwchange:' . $userId;
    }

    private function sessionResponse(LoginResult $result): ResponseInterface
    {
        $body = $result->mfaRequired
            ? ['status' => 'mfa_required']
            : [
                'status' => 'ok',
                'user' => UserService::toArray($result->user, $this->totp->isEnabled($result->user->id()), $this->users->siteRoles($result->user->id()), $this->users->pendingEmail($result->user->id())),
                'csrf_token' => $result->session->csrfSecret,
            ];

        return $this->responder->data($body)->withAddedHeader('Set-Cookie', $this->sessions->cookieHeader($result->token, $result->session));
    }
}
