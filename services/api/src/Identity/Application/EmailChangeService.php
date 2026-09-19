<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\AuthSession;
use Analytics\Identity\Domain\EmailChange;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Mail\Mailer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Changing the sign-in address: the change is stored as pending and applied only when the link sent
 * to the new address is opened, which proves the user controls it. Tokens follow password_resets:
 * only the hash is stored, they expire after 24 hours and work once.
 */
final readonly class EmailChangeService
{
    public const string TTL = 'PT24H';

    public function __construct(
        private EntityManagerInterface $em,
        private UserService $users,
        private SessionManager $sessions,
        private Mailer $mailer,
        private IdentityMails $mails,
        private ClockInterface $clock,
    ) {}

    public function isAvailable(): bool
    {
        return $this->mailer->isEnabled();
    }

    public function assertAvailable(): void
    {
        if (!$this->mailer->isEnabled()) {
            throw new ApiProblem(501, 'mailer_disabled', 'Not implemented', 'Changing the email address needs a configured mailer. Ask an administrator to configure MAILER_DSN.');
        }
    }

    /**
     * Stores the pending change (replacing any previous one) and mails the confirmation link to the
     * new address. The caller has verified the current password. Returns the normalised address.
     */
    public function request(User $user, string $newEmail): string
    {
        $this->assertAvailable();
        $newEmail = User::normalizeEmail($newEmail);
        if ($newEmail === $user->email) {
            throw ApiProblem::validation(['email' => ['This is already the address of the account.']]);
        }
        $this->assertFree($newEmail, $user);

        $token = TokenGenerator::base64Url(32);
        $now = $this->clock->now();
        $this->em->wrapInTransaction(function () use ($user, $newEmail, $token, $now): void {
            $this->deletePending($user->id());
            $this->em->persist(new EmailChange(TokenHasher::hash($token), $user->id(), $newEmail, $now->add(new \DateInterval(self::TTL)), $now));
        });
        $this->mailer->send($this->mails->emailChangeConfirmation($user, $newEmail, $token));

        return $newEmail;
    }

    public function cancel(User $user): void
    {
        $this->deletePending($user->id());
    }

    /**
     * Applies the change for a token from the link. Every session of the user except $keep is revoked
     * and the previous address gets a notice.
     *
     * @return array{user: User, old_email: string, revoked_sessions: int}
     */
    public function confirm(string $token, ?AuthSession $keep = null): array
    {
        $change = preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? $this->em->find(EmailChange::class, TokenHasher::hash($token)) : null;
        $now = $this->clock->now();
        if (!$change instanceof EmailChange || $change->usedAt !== null || $change->expiresAt <= $now) {
            throw new ApiProblem(410, 'email_change_token_invalid', 'Gone', 'This confirmation link is invalid, expired or already used.');
        }
        $user = $this->em->find(User::class, $change->userId);
        if (!$user instanceof User || !$user->isActive()) {
            throw new ApiProblem(410, 'email_change_token_invalid', 'Gone', 'This confirmation link is invalid, expired or already used.');
        }
        $this->assertFree($change->newEmail, $user);

        $oldEmail = $user->email;
        try {
            $this->em->wrapInTransaction(function () use ($user, $change, $now): void {
                $user->email = $change->newEmail;
                $user->updatedAt = $now;
                $change->usedAt = $now;
                $this->em->flush();
                // Any other pending request of this user is now moot.
                $this->deletePending($user->id());
            });
        } catch (UniqueConstraintViolationException) {
            // Another account took the address between the check and the write.
            throw self::taken();
        }
        $revoked = $this->sessions->revokeAll($user->id(), $keep !== null && $keep->userId === $user->id() ? $keep : null);
        $this->mailer->send($this->mails->emailChanged($user, $oldEmail, $now));

        return ['user' => $user, 'old_email' => $oldEmail, 'revoked_sessions' => $revoked];
    }

    private function assertFree(string $email, User $self): void
    {
        $other = $this->users->findByEmail($email);
        if ($other !== null && $other->id() !== $self->id()) {
            throw self::taken();
        }
    }

    private static function taken(): ApiProblem
    {
        return ApiProblem::conflict('email_taken', 'Another account already uses this email address.');
    }

    /** Removes the unconfirmed requests of a user (through the ORM, so no stale entity survives). */
    private function deletePending(int $userId): void
    {
        foreach ($this->em->getRepository(EmailChange::class)->findBy(['userId' => $userId, 'usedAt' => null]) as $pending) {
            $this->em->remove($pending);
        }
        $this->em->flush();
    }
}
