<?php

declare(strict_types=1);

namespace Analytics\Sites\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\Authorizer;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Application\SiteService;
use Analytics\Sites\Application\SnippetRenderer;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SitesController
{
    public function __construct(
        private JsonResponder $responder,
        private SiteRepository $sites,
        private SiteService $service,
        private SnippetRenderer $snippets,
        private Authorizer $authorizer,
        private UserService $users,
        private AuditLogger $audit,
        private Connection $connection,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestContext::user($request);
        $includeArchived = ($request->getQueryParams()['archived'] ?? '') === '1' && $user->isAdmin();
        $ids = $this->authorizer->accessibleSiteIds($user);
        $data = [];
        foreach ($this->sites->all($includeArchived) as $site) {
            if ($ids !== null && !\in_array($site->id(), $ids, true)) {
                continue;
            }
            $data[] = SiteService::toArray($site, $this->authorizer->siteRole($user, $site->id())?->value);
        }

        return $this->responder->data($data);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->service->createFromInput(RequestContext::body($request));
        $this->audit->log('site.created', RequestContext::actor($request), $site->id(), 'site', $site->id(), ['name' => $site->name]);

        return $this->responder->json(['data' => SiteService::toArray($site, SiteRole::Admin->value)], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $role = $request->getAttribute(\Analytics\Shared\Http\RequestAttributes::SITE_ROLE);

        return $this->responder->data(SiteService::toArray($site, $role instanceof SiteRole ? $role->value : null));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $input = RequestContext::body($request);
        $result = $this->service->update($site, $input);
        $this->audit->log('site.updated', RequestContext::actor($request), $site->id(), 'site', $site->id(), ['fields' => array_keys($input->raw())]);

        return $this->responder->data(SiteService::toArray($site, SiteRole::Admin->value) + ['timezone_changed' => $result['timezone_changed']]);
    }

    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $this->service->archive($site);
        $this->audit->log('site.archived', RequestContext::actor($request), $site->id(), 'site', $site->id());

        return $this->responder->noContent();
    }

    public function snippet(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data($this->snippets->render($site->publicKey, $site->trackerGlobal) + ['public_key' => $site->publicKey]);
    }

    public function members(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.email, u.display_name, u.status, u.global_role, r.role
               FROM users u LEFT JOIN user_site_roles r ON r.user_id = u.id AND r.site_id = ?
              WHERE r.role IS NOT NULL OR u.global_role = 'admin'
              ORDER BY u.email",
            [$site->id()],
        );
        $data = array_map(static fn(array $r): array => [
            'user_id' => Types::int($r['id']),
            'email' => Types::string($r['email']),
            'display_name' => Types::string($r['display_name']),
            'status' => Types::string($r['status']),
            'global_role' => Types::string($r['global_role']),
            'role' => $r['global_role'] === 'admin' ? 'admin' : Types::string($r['role']),
            'inherited' => $r['global_role'] === 'admin',
        ], $rows);

        return $this->responder->data($data);
    }

    public function setMember(ServerRequestInterface $request, string $userId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $input = RequestContext::body($request);
        $role = $input->enum('role', SiteRole::class);
        $input->assertValid();
        \assert($role instanceof SiteRole);
        $user = $this->users->get((int) $userId);
        $this->guardSelfDemotion($request, $user, $role);
        $this->users->setSiteRole($user->id(), $site->id(), $role);
        $this->audit->log('site.member_set', RequestContext::actor($request), $site->id(), 'user', $user->id(), ['role' => $role->value]);

        return $this->members($request);
    }

    public function addMemberByEmail(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $input = RequestContext::body($request);
        $email = $input->email('email');
        $role = $input->enum('role', SiteRole::class, SiteRole::Viewer);
        $input->assertValid();
        \assert($role instanceof SiteRole);
        $user = $this->users->findByEmail($email) ?? throw ApiProblem::notFound('No user with this email. Send an invitation instead.');
        $this->users->setSiteRole($user->id(), $site->id(), $role);
        $this->audit->log('site.member_set', RequestContext::actor($request), $site->id(), 'user', $user->id(), ['role' => $role->value]);

        return $this->members($request);
    }

    public function removeMember(ServerRequestInterface $request, string $userId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $user = $this->users->get((int) $userId);
        $this->guardSelfDemotion($request, $user, null);
        $this->users->setSiteRole($user->id(), $site->id(), null);
        $this->audit->log('site.member_removed', RequestContext::actor($request), $site->id(), 'user', $user->id());

        return $this->responder->noContent();
    }

    private function guardSelfDemotion(ServerRequestInterface $request, User $target, ?SiteRole $role): void
    {
        $self = RequestContext::user($request);
        if ($target->id() === $self->id() && !$self->isAdmin() && $role !== SiteRole::Admin) {
            throw ApiProblem::conflict('cannot_demote_self', 'You cannot remove your own admin access.');
        }
    }
}
