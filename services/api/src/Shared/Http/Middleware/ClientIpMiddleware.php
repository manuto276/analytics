<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Analytics\Shared\Http\RequestAttributes;
use Analytics\Shared\Net\ClientIpResolver;
use Analytics\Shared\Net\IpTruncator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Outermost privacy boundary: resolves the client address, shortens it and removes every trace
 * of the full address from the request before any other code runs.
 */
final readonly class ClientIpMiddleware implements MiddlewareInterface
{
    public function __construct(private ClientIpResolver $resolver) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolver->resolve($request);
        $prefix = $ip === null ? null : IpTruncator::truncate($ip);

        $request = self::scrub($request)->withAttribute(RequestAttributes::IP_PREFIX, $prefix);

        return $handler->handle($request);
    }

    public static function scrub(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach (['X-Forwarded-For', 'X-Real-Ip', 'Forwarded', 'Client-Ip', 'True-Client-Ip', 'Cf-Connecting-Ip', 'X-Client-Ip'] as $header) {
            $request = $request->withoutHeader($header);
        }
        $server = $request->getServerParams();
        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_CLIENT_IP'] as $key) {
            unset($server[$key]);
        }
        if ($request instanceof \Slim\Psr7\Request) {
            // Slim's request keeps server params immutable; rebuild with scrubbed params.
            $request = new \Slim\Psr7\Request(
                $request->getMethod(),
                $request->getUri(),
                new \Slim\Psr7\Headers($request->getHeaders()),
                $request->getCookieParams(),
                $server,
                $request->getBody(),
                $request->getUploadedFiles(),
            );
            foreach ($request->getAttributes() as $name => $value) {
                $request = $request->withAttribute((string) $name, $value);
            }
        }

        return $request;
    }
}
