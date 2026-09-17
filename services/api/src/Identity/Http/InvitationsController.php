<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\InvitationService;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\Invitation;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\RateLimit\RateLimiter;
use Analytics\Sites\Application\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class InvitationsController
{
    public function __construct(
        private JsonResponder $responder,
        private EntityManagerInterface $em,
        private InvitationService $invitations,
        private SiteRepository $sites,
        private SessionManager $sessions,
        private AuditLogger $audit,
        private RateLimiter $rateLimiter,
    ) {}

    public function list(): ResponseInterface
    {
        /** @var list<Invitation> $items */
        $items = $this->em->getRepository(Invitation::class)->findBy([], ['createdAt' => 'DESC'], 200);

        return $this->responder->data(array_map($this->invitations->toArray(...), $items));
    }

    /** Invitations that grant access to this site (site admin). */
    public function listForSite(ServerRequestInterface $request, string $siteId): ResponseInterface
    {
        /** @var list<Invitation> $items */
        $items = $this->em->getRepository(Invitation::class)->findBy([], ['createdAt' => 'DESC'], 500);
        $forSite = array_values(array_filter($items, static function (Invitation $invitation) use ($siteId): bool {
            foreach ($invitation->siteRoles as $role) {
                if ((int) $role['site_id'] === (int) $siteId) {
                    return true;
                }
            }

            return false;
        }));

        return $this->responder->data(array_map($this->invitations->toArray(...), $forSite));
    }

    public function revokeForSite(ServerRequestInterface $request, string $siteId, string $invitationId): ResponseInterface
    {
        $invitation = $this->em->find(Invitation::class, (int) $invitationId);
        $belongs = false;
        foreach ($invitation instanceof Invitation ? $invitation->siteRoles : [] as $role) {
            $belongs = $belongs || (int) $role['site_id'] === (int) $siteId;
        }
        if (!$invitation instanceof Invitation || !$belongs) {
            throw \Analytics\Shared\Http\ApiProblem::notFound('Invitation not found.');
        }

        return $this->revoke($request, $invitationId);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $role = $input->enum('global_role', GlobalRole::class, GlobalRole::Member);
        $siteRoles = [];
        foreach ($input->array('site_roles') ?? [] as $i => $entry) {
            $entryInput = new \Analytics\Shared\Validation\Input(\is_array($entry) ? $entry : [], 'site_roles.' . $i . '.');
            $siteId = $entryInput->int('site_id', null, 1);
            $siteRole = $entryInput->enum('role', SiteRole::class);
            $input->merge($entryInput);
            if ($siteId > 0 && $this->sites->find($siteId) === null) {
                $input->error('site_roles.' . $i . '.site_id', 'Site not found.');
            }
            if ($siteRole instanceof SiteRole && $siteId > 0) {
                $siteRoles[] = ['site_id' => $siteId, 'role' => $siteRole];
            }
        }
        $input->assertValid();
        \assert($role instanceof GlobalRole);

        return $this->created($request, $email, $role, $siteRoles, null);
    }

    /** Site admins invite people to their own site. */
    public function createForSite(ServerRequestInterface $request, string $siteId): ResponseInterface
    {
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $role = $input->enum('role', SiteRole::class, SiteRole::Viewer);
        $input->assertValid();
        \assert($role instanceof SiteRole);

        return $this->created($request, $email, GlobalRole::Member, [['site_id' => (int) $siteId, 'role' => $role]], (int) $siteId);
    }

    public function revoke(ServerRequestInterface $request, string $invitationId): ResponseInterface
    {
        $invitation = $this->invitations->revoke((int) $invitationId);
        $this->audit->log('invitation.revoked', RequestContext::actor($request), null, 'invitation', $invitation->id());

        return $this->responder->data($this->invitations->toArray($invitation));
    }

    public function showPublic(ServerRequestInterface $request, string $token): ResponseInterface
    {
        $this->rateLimiter->enforce('public', 'invite:' . (RequestContext::ipPrefixString($request) ?? '-'));
        $invitation = $this->invitations->findPending($token);
        $sites = [];
        foreach ($invitation->siteRoles as $siteRole) {
            $site = $this->sites->find($siteRole['site_id']);
            if ($site !== null) {
                $sites[] = ['name' => $site->name, 'role' => $siteRole['role']];
            }
        }

        return $this->responder->data([
            'email' => $invitation->email,
            'global_role' => $invitation->globalRole->value,
            'sites' => $sites,
            'expires_at' => $invitation->expiresAt->format(\DATE_ATOM),
        ]);
    }

    public function accept(ServerRequestInterface $request, string $token): ResponseInterface
    {
        $this->rateLimiter->enforce('public', 'invite:' . (RequestContext::ipPrefixString($request) ?? '-'));
        $input = RequestContext::body($request);
        $displayName = $input->string('display_name', 120);
        $password = $input->secret('password');
        $locale = $input->choice('locale', UserService::LOCALES, 'en');
        $input->assertValid();

        $user = $this->invitations->accept($token, $displayName, $password, $locale);
        $this->audit->log('invitation.accepted', new \Analytics\Audit\Application\Actor('user', $user->id(), RequestContext::ipPrefixString($request)), null, 'user', $user->id());
        [$sessionToken, $session] = $this->sessions->start($user, SessionState::Active, RequestContext::ipPrefixString($request), RequestContext::uaSummary($request));

        return $this->responder->json(['data' => [
            'status' => 'ok',
            'user' => UserService::toArray($user, false),
            'csrf_token' => $session->csrfSecret,
        ]], 201)->withAddedHeader('Set-Cookie', $this->sessions->cookieHeader($sessionToken, $session));
    }

    /**
     * @param list<array{site_id: int, role: SiteRole}> $siteRoles
     */
    private function created(ServerRequestInterface $request, string $email, GlobalRole $role, array $siteRoles, ?int $siteId): ResponseInterface
    {
        [$invitation, $token] = $this->invitations->create($email, $role, $siteRoles, RequestContext::user($request)->id());
        $this->audit->log('invitation.created', RequestContext::actor($request), $siteId, 'invitation', $invitation->id(), ['global_role' => $role->value, 'sites' => \count($siteRoles)]);

        return $this->responder->json(['data' => $this->invitations->toArray($invitation) + ['link' => $this->invitations->link($token)]], 201);
    }
}
