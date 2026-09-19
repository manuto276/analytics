<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\User;
use Analytics\Identity\Domain\UserStatus;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UsersController
{
    public function __construct(
        private JsonResponder $responder,
        private EntityManagerInterface $em,
        private UserService $users,
        private TotpService $totp,
        private SessionManager $sessions,
        private AuditLogger $audit,
    ) {}

    public function list(): ResponseInterface
    {
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC']);
        $data = array_map(fn(User $u): array => UserService::toArray($u, $this->totp->isEnabled($u->id()), $this->users->siteRoles($u->id()), $this->users->pendingEmail($u->id())), $users);

        return $this->responder->data($data);
    }

    public function show(string $userId): ResponseInterface
    {
        $user = $this->users->get((int) $userId);

        return $this->responder->data(UserService::toArray($user, $this->totp->isEnabled($user->id()), $this->users->siteRoles($user->id()), $this->users->pendingEmail($user->id())));
    }

    public function update(ServerRequestInterface $request, string $userId): ResponseInterface
    {
        $user = $this->users->get((int) $userId);
        $input = RequestContext::body($request);
        $changes = [];
        if ($input->has('display_name')) {
            $user->displayName = $input->string('display_name', 120);
            $changes[] = 'display_name';
        }
        $role = $input->has('global_role') ? $input->enum('global_role', GlobalRole::class) : null;
        $status = $input->has('status') ? $input->enum('status', UserStatus::class) : null;
        $input->assertValid();

        $self = RequestContext::user($request);
        if ($role instanceof GlobalRole && $role !== $user->globalRole) {
            $this->users->setGlobalRole($user, $role);
            $changes[] = 'global_role';
        }
        if ($status instanceof UserStatus && $status !== $user->status) {
            if ($user->id() === $self->id()) {
                throw ApiProblem::conflict('cannot_disable_self', 'You cannot disable your own account.');
            }
            $this->users->setStatus($user, $status);
            if ($status === UserStatus::Disabled) {
                $this->sessions->revokeAll($user->id());
            }
            $changes[] = 'status';
        }
        $this->users->save($user);
        $this->audit->log('user.updated', RequestContext::actor($request), null, 'user', $user->id(), ['fields' => $changes]);

        return $this->show($userId);
    }

    public function resetTotp(ServerRequestInterface $request, string $userId): ResponseInterface
    {
        $user = $this->users->get((int) $userId);
        $this->totp->disable($user->id());
        $this->sessions->revokeAll($user->id());
        $this->audit->log('user.totp_reset', RequestContext::actor($request), null, 'user', $user->id());

        return $this->responder->noContent();
    }
}
