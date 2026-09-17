<?php

declare(strict_types=1);

namespace Analytics\Kernel\Http;

use Analytics\Audit\Application\Actor;
use Analytics\Identity\Domain\AuthSession;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\RequestAttributes;
use Analytics\Shared\Net\IpPrefix;
use Analytics\Shared\Validation\Input;
use Analytics\Sites\Domain\Site;
use Psr\Http\Message\ServerRequestInterface;

final class RequestContext
{
    public static function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(RequestAttributes::USER);

        return $user instanceof User ? $user : throw ApiProblem::unauthorized();
    }

    public static function session(ServerRequestInterface $request): AuthSession
    {
        $session = $request->getAttribute(RequestAttributes::AUTH_SESSION);

        return $session instanceof AuthSession ? $session : throw ApiProblem::unauthorized();
    }

    public static function site(ServerRequestInterface $request): Site
    {
        $site = $request->getAttribute(RequestAttributes::SITE);

        return $site instanceof Site ? $site : throw ApiProblem::notFound('Site not found.');
    }

    public static function ipPrefix(ServerRequestInterface $request): ?IpPrefix
    {
        $prefix = $request->getAttribute(RequestAttributes::IP_PREFIX);

        return $prefix instanceof IpPrefix ? $prefix : null;
    }

    public static function ipPrefixString(ServerRequestInterface $request): ?string
    {
        $prefix = self::ipPrefix($request);

        return $prefix === null ? null : (string) $prefix;
    }

    public static function actor(ServerRequestInterface $request): Actor
    {
        $user = $request->getAttribute(RequestAttributes::USER);

        return new Actor('user', $user instanceof User ? $user->id : null, self::ipPrefixString($request));
    }

    public static function body(ServerRequestInterface $request): Input
    {
        return Input::fromBody($request->getParsedBody());
    }

    public static function query(ServerRequestInterface $request): Input
    {
        return new Input($request->getQueryParams());
    }

    /** Short "Browser on OS" description for session lists. The full UA is never stored. */
    public static function uaSummary(ServerRequestInterface $request): ?string
    {
        $ua = $request->getHeaderLine('User-Agent');
        if ($ua === '') {
            return null;
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return $browser . ' on ' . $os;
    }
}
