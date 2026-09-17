<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\PasswordReset;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Mail\Mailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class PasswordResetService
{
    public const string TTL = 'PT1H';

    public function __construct(
        private EntityManagerInterface $em,
        private UserService $users,
        private SessionManager $sessions,
        private Mailer $mailer,
        private ClockInterface $clock,
        private string $appUrl,
    ) {}

    public function isAvailable(): bool
    {
        return $this->mailer->isEnabled();
    }

    /** Always succeeds silently to avoid account enumeration. */
    public function request(string $email): void
    {
        if (!$this->mailer->isEnabled()) {
            throw new ApiProblem(501, 'mailer_disabled', 'Not implemented', 'Password reset by email is not configured. Ask an administrator to run user:set-password.');
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->isActive()) {
            return;
        }
        $token = TokenGenerator::base64Url(32);
        $this->em->persist(new PasswordReset(TokenHasher::hash($token), $user->id(), $this->clock->now()->add(new \DateInterval(self::TTL))));
        $this->em->flush();
        $this->mailer->send(
            $user->email,
            'Reset your analytics password',
            "Someone asked to reset the password of your analytics account.\n\nOpen this link within one hour to choose a new password:\n" . $this->appUrl . '/password/reset/' . $token . "\n\nIf this was not you, ignore this email.",
        );
    }

    public function reset(string $token, string $password): void
    {
        $reset = preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? $this->em->find(PasswordReset::class, TokenHasher::hash($token)) : null;
        if (!$reset instanceof PasswordReset || $reset->usedAt !== null || $reset->expiresAt <= $this->clock->now()) {
            throw new ApiProblem(410, 'reset_token_invalid', 'Gone', 'This reset link is invalid or expired.');
        }
        $user = $this->users->get($reset->userId);
        $this->users->setPassword($user, $password);
        $reset->usedAt = $this->clock->now();
        $this->em->flush();
        $this->sessions->revokeAll($user->id());
    }
}
