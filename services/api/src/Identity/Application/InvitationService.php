<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\Invitation;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use Analytics\Shared\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class InvitationService
{
    public const string TTL = 'P7D';

    public function __construct(
        private EntityManagerInterface $em,
        private UserService $users,
        private ClockInterface $clock,
        private string $appUrl,
    ) {}

    /**
     * @param list<array{site_id: int, role: SiteRole}> $siteRoles
     *
     * @return array{0: Invitation, 1: string} invitation and plain token
     */
    public function create(string $email, GlobalRole $role, array $siteRoles, ?int $invitedBy): array
    {
        $email = User::normalizeEmail($email);
        if ($this->users->findByEmail($email) !== null) {
            throw ApiProblem::conflict('email_taken', 'A user with this email already exists; grant site access from Members instead.');
        }
        $token = TokenGenerator::base64Url(32);
        $now = $this->clock->now();
        $invitation = new Invitation(
            email: $email,
            tokenHash: TokenHasher::hash($token),
            globalRole: $role,
            siteRoles: array_map(static fn(array $r): array => ['site_id' => $r['site_id'], 'role' => $r['role']->value], $siteRoles),
            invitedBy: $invitedBy,
            expiresAt: $now->add(new \DateInterval(self::TTL)),
            createdAt: $now,
        );
        $this->em->persist($invitation);
        $this->em->flush();

        return [$invitation, $token];
    }

    public function link(string $token): string
    {
        return $this->appUrl . '/invite/' . $token;
    }

    public function findPending(string $token): Invitation
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw ApiProblem::notFound('Invitation not found.');
        }
        $invitation = $this->em->getRepository(Invitation::class)->findOneBy(['tokenHash' => TokenHasher::hash($token)]);
        if (!$invitation instanceof Invitation) {
            throw ApiProblem::notFound('Invitation not found.');
        }
        $status = $invitation->status($this->clock->now());
        if ($status !== 'pending') {
            throw new ApiProblem(410, 'invitation_' . $status, 'Gone', 'This invitation is ' . $status . '.');
        }

        return $invitation;
    }

    public function accept(string $token, string $displayName, string $password, string $locale): User
    {
        $invitation = $this->findPending($token);
        $violations = PasswordHasher::validatePolicy($password, $invitation->email);
        if ($violations !== []) {
            throw ApiProblem::validation(['password' => $violations]);
        }
        $user = null;
        $this->em->getConnection()->transactional(function () use ($invitation, $displayName, $password, $locale, &$user): void {
            $user = $this->users->create($invitation->email, $password, $displayName, $invitation->globalRole, $locale);
            foreach ($invitation->siteRoles as $siteRole) {
                $site = $this->em->getConnection()->fetchOne('SELECT id FROM sites WHERE id = ? AND archived_at IS NULL', [$siteRole['site_id']]);
                if ($site !== false) {
                    $this->users->setSiteRole($user->id(), (int) $siteRole['site_id'], SiteRole::from($siteRole['role']));
                }
            }
            $invitation->acceptedAt = $this->clock->now();
            $this->em->flush();
        });
        \assert($user instanceof User);

        return $user;
    }

    public function revoke(int $id): Invitation
    {
        $invitation = $this->em->find(Invitation::class, $id);
        if (!$invitation instanceof Invitation) {
            throw ApiProblem::notFound('Invitation not found.');
        }
        if ($invitation->acceptedAt === null && $invitation->revokedAt === null) {
            $invitation->revokedAt = $this->clock->now();
            $this->em->flush();
        }

        return $invitation;
    }

    /** @return array<string, mixed> */
    public function toArray(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id(),
            'email' => $invitation->email,
            'global_role' => $invitation->globalRole->value,
            'site_roles' => $invitation->siteRoles,
            'status' => $invitation->status($this->clock->now()),
            'expires_at' => $invitation->expiresAt->format(\DATE_ATOM),
            'created_at' => $invitation->createdAt->format(\DATE_ATOM),
        ];
    }
}
