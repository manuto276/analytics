<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\AuthSession;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use Analytics\Shared\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SessionManager
{
    public const string IDLE_TTL = 'PT12H';
    public const string ABSOLUTE_TTL = 'P30D';
    public const string PENDING_MFA_TTL = 'PT10M';
    private const int TOUCH_INTERVAL_SECONDS = 60;

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private bool $secureCookie,
    ) {}

    public function cookieName(): string
    {
        return $this->secureCookie ? '__Host-an_session' : 'an_session';
    }

    /** @return array{0: string, 1: AuthSession} token and session */
    public function start(User $user, SessionState $state, ?string $ipPrefix, ?string $uaSummary): array
    {
        $now = $this->clock->now();
        $token = TokenGenerator::base64Url(32);
        $absolute = $state === SessionState::PendingMfa ? $now->add(new \DateInterval(self::PENDING_MFA_TTL)) : $now->add(new \DateInterval(self::ABSOLUTE_TTL));
        $idle = $state === SessionState::PendingMfa ? $absolute : $now->add(new \DateInterval(self::IDLE_TTL));
        $session = new AuthSession(
            id: TokenHasher::hash($token),
            userId: $user->id(),
            state: $state,
            csrfSecret: TokenGenerator::base64Url(32),
            createdAt: $now,
            lastSeenAt: $now,
            idleExpiresAt: $idle,
            absoluteExpiresAt: $absolute,
            ipPrefix: $ipPrefix,
            uaSummary: $uaSummary === null ? null : mb_substr($uaSummary, 0, 120),
        );
        $this->em->persist($session);
        $this->em->flush();

        return [$token, $session];
    }

    public function resolve(string $token): ?AuthSession
    {
        if ($token === '' || \strlen($token) > 128) {
            return null;
        }
        $session = $this->em->find(AuthSession::class, TokenHasher::hash($token));
        if (!$session instanceof AuthSession) {
            return null;
        }
        $now = $this->clock->now();
        if (!$session->isValid($now)) {
            return null;
        }
        if ($session->state === SessionState::Active && $now->getTimestamp() - $session->lastSeenAt->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS) {
            $session->lastSeenAt = $now;
            $idle = $now->add(new \DateInterval(self::IDLE_TTL));
            $session->idleExpiresAt = $idle < $session->absoluteExpiresAt ? $idle : $session->absoluteExpiresAt;
            $this->em->flush();
        }

        return $session;
    }

    public function revoke(AuthSession $session): void
    {
        $session->revokedAt = $this->clock->now();
        $this->em->flush();
    }

    /** Revokes all sessions of a user except $keep. Returns the number revoked. */
    public function revokeAll(int $userId, ?AuthSession $keep = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->update(AuthSession::class, 's')
            ->set('s.revokedAt', ':now')
            ->where('s.userId = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('now', $this->clock->now(), 'datetime_immutable')
            ->setParameter('user', $userId);
        if ($keep !== null) {
            $qb->andWhere('s.id <> :keep')->setParameter('keep', $keep->id, 'binary');
        }
        $count = Types::int($qb->getQuery()->execute());
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[AuthSession::class] ?? [] as $managed) {
            if ($managed instanceof AuthSession && $managed->userId === $userId) {
                $this->em->refresh($managed);
            }
        }

        return $count;
    }

    /** @return list<AuthSession> */
    public function activeSessions(int $userId): array
    {
        /** @var list<AuthSession> $sessions */
        $sessions = $this->em->createQueryBuilder()
            ->select('s')->from(AuthSession::class, 's')
            ->where('s.userId = :user')->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.absoluteExpiresAt > :now')->andWhere('s.idleExpiresAt > :now')
            ->andWhere('s.state = :state')
            ->setParameter('user', $userId)->setParameter('now', $this->clock->now(), 'datetime_immutable')
            ->setParameter('state', SessionState::Active->value)
            ->orderBy('s.lastSeenAt', 'DESC')
            ->getQuery()->getResult();

        return $sessions;
    }

    public static function publicId(AuthSession $session): string
    {
        return bin2hex(substr($session->id, 0, 8));
    }

    public function cookieHeader(string $token, AuthSession $session): string
    {
        $maxAge = max(0, $session->absoluteExpiresAt->getTimestamp() - $this->clock->now()->getTimestamp());

        return \sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s', $this->cookieName(), $token, $maxAge, $this->secureCookie ? '; Secure' : '');
    }

    public function clearCookieHeader(): string
    {
        return \sprintf('%s=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax%s', $this->cookieName(), $this->secureCookie ? '; Secure' : '');
    }
}
