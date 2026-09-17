<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Domain\AuthSession;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\RateLimit\RateLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class LoginService
{
    public const int LOCK_THRESHOLD = 5;
    public const int MAX_LOCK_MINUTES = 60;

    public function __construct(
        private EntityManagerInterface $em,
        private PasswordHasher $hasher,
        private SessionManager $sessions,
        private TotpService $totp,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {}

    public function login(string $email, string $password, ?string $ipPrefix, ?string $uaSummary): LoginResult
    {
        $email = User::normalizeEmail($email);
        $this->rateLimiter->enforce('login_ip', 'ip:' . ($ipPrefix ?? 'unknown'));
        $this->rateLimiter->enforce('login_email', 'email:' . hash('sha256', $email));

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $now = $this->clock->now();

        if (!$user instanceof User) {
            $this->hasher->dummyVerify($password);
            $this->audit->log('auth.login_failed', new Actor('user', null, $ipPrefix), null, 'user', null, ['reason' => 'unknown_email']);

            throw self::invalidCredentials();
        }

        if ($user->lockedUntil !== null && $user->lockedUntil > $now) {
            $this->hasher->dummyVerify($password);
            $retry = max(1, $user->lockedUntil->getTimestamp() - $now->getTimestamp());

            throw new ApiProblem(429, 'account_locked', 'Too many requests', 'Too many failed attempts. Try again later.', [], ['retry_after' => $retry], ['Retry-After' => (string) $retry]);
        }

        if (!$this->hasher->verify($password, $user->passwordHash) || !$user->isActive()) {
            if ($user->isActive()) {
                ++$user->failedLogins;
                if ($user->failedLogins >= self::LOCK_THRESHOLD) {
                    $minutes = min(self::MAX_LOCK_MINUTES, 2 ** ($user->failedLogins - self::LOCK_THRESHOLD));
                    $user->lockedUntil = $now->modify('+' . $minutes . ' minutes');
                }
                $this->em->flush();
            }
            $this->audit->log('auth.login_failed', new Actor('user', $user->id(), $ipPrefix), null, 'user', $user->id(), ['reason' => $user->isActive() ? 'bad_password' : 'disabled']);

            throw self::invalidCredentials();
        }

        $user->failedLogins = 0;
        $user->lockedUntil = null;
        if (password_needs_rehash($user->passwordHash, \PASSWORD_ARGON2ID) && !str_starts_with($user->passwordHash, '$argon2id$')) {
            $user->passwordHash = $this->hasher->hash($password);
        }

        $mfa = $this->totp->isEnabled($user->id());
        if (!$mfa) {
            $user->lastLoginAt = $now;
        }
        $this->em->flush();

        [$token, $session] = $this->sessions->start($user, $mfa ? SessionState::PendingMfa : SessionState::Active, $ipPrefix, $uaSummary);
        $this->audit->log($mfa ? 'auth.login_mfa_pending' : 'auth.login', new Actor('user', $user->id(), $ipPrefix), null, 'user', $user->id());

        return new LoginResult($user, $session, $token, $mfa);
    }

    /** Completes MFA for a pending session; rotates the session token. */
    public function completeMfa(AuthSession $pending, string $code, ?string $ipPrefix, ?string $uaSummary): LoginResult
    {
        if ($pending->state !== SessionState::PendingMfa) {
            throw ApiProblem::badRequest('mfa_not_pending', 'This session does not require MFA.');
        }
        $this->rateLimiter->enforce('login_ip', 'mfa:' . bin2hex($pending->id));
        $user = $this->em->find(User::class, $pending->userId);
        if (!$user instanceof User || !$user->isActive()) {
            throw ApiProblem::unauthorized();
        }
        if (!$this->totp->verifyLogin($user->id(), $code)) {
            $this->audit->log('auth.mfa_failed', new Actor('user', $user->id(), $ipPrefix), null, 'user', $user->id());

            throw new ApiProblem(401, 'invalid_mfa_code', 'Unauthorized', 'The verification code is not valid.');
        }
        $this->sessions->revoke($pending);
        $user->lastLoginAt = $this->clock->now();
        $this->em->flush();
        [$token, $session] = $this->sessions->start($user, SessionState::Active, $ipPrefix, $uaSummary);
        $this->audit->log('auth.login', new Actor('user', $user->id(), $ipPrefix), null, 'user', $user->id(), ['mfa' => true]);

        return new LoginResult($user, $session, $token, false);
    }

    private static function invalidCredentials(): ApiProblem
    {
        return new ApiProblem(401, 'invalid_credentials', 'Unauthorized', 'Email or password is incorrect.');
    }
}
