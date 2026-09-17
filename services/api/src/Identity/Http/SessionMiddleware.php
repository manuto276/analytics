<?php

declare(strict_types=1);

namespace Analytics\Identity\Http;

use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\RequestAttributes;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionManager $sessions, private EntityManagerInterface $em) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = self::token($request, $this->sessions->cookieName());
        $session = $token === null ? null : $this->sessions->resolve($token);
        if ($session === null) {
            throw ApiProblem::unauthorized();
        }
        if ($session->state === SessionState::PendingMfa) {
            throw ApiProblem::unauthorized('Multi-factor authentication required.', 'mfa_required');
        }
        $user = $this->em->find(User::class, $session->userId);
        if (!$user instanceof User || !$user->isActive()) {
            $this->sessions->revoke($session);

            throw ApiProblem::unauthorized();
        }

        return $handler->handle($request
            ->withAttribute(RequestAttributes::AUTH_SESSION, $session)
            ->withAttribute(RequestAttributes::USER, $user));
    }

    public static function token(ServerRequestInterface $request, string $cookieName): ?string
    {
        $cookies = $request->getCookieParams();
        $value = $cookies[$cookieName] ?? null;
        if (\is_string($value) && $value !== '') {
            return $value;
        }
        // Fallback when cookie params were not populated (e.g. custom server setups).
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (\count($parts) === 2 && $parts[0] === $cookieName && $parts[1] !== '') {
                return $parts[1];
            }
        }

        return null;
    }
}
