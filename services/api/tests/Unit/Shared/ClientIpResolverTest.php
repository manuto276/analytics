<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Shared\Http\Middleware\ClientIpMiddleware;
use Analytics\Shared\Http\RequestAttributes;
use Analytics\Shared\Net\ClientIpResolver;
use Analytics\Shared\Net\IpPrefix;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpResolverTest extends TestCase
{
    private function request(string $remote, ?string $xff = null): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://analytics.test/', ['REMOTE_ADDR' => $remote]);

        return $xff === null ? $request : $request->withHeader('X-Forwarded-For', $xff);
    }

    public function testIgnoresForwardedHeaderFromUntrustedPeer(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.0/8']);
        self::assertSame('198.51.100.1', $resolver->resolve($this->request('198.51.100.1', '203.0.113.5')));
    }

    public function testUsesRightmostUntrustedHop(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.0/8', '172.16.0.1']);
        self::assertSame('203.0.113.5', $resolver->resolve($this->request('10.1.2.3', '192.0.2.99, 203.0.113.5, 172.16.0.1')));
        self::assertSame('10.9.9.9', $resolver->resolve($this->request('10.1.2.3', '10.9.9.9')));
        self::assertSame('10.1.2.3', $resolver->resolve($this->request('10.1.2.3')));
        self::assertSame('10.1.2.3', $resolver->resolve($this->request('10.1.2.3', '203.0.113.5, garbage')));
        self::assertSame('2001:db8::1', $resolver->resolve($this->request('10.1.2.3', '[2001:db8::1]')));
    }

    public function testMiddlewareExposesOnlyPrefixAndScrubsFullAddress(): void
    {
        $middleware = new ClientIpMiddleware(new ClientIpResolver(['10.0.0.0/8']));
        $request = $this->request('10.0.0.5', '203.0.113.77')->withHeader('X-Real-Ip', '203.0.113.77')->withHeader('Forwarded', 'for=203.0.113.77');
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new ResponseFactory()->createResponse();
            }
        };

        $middleware->process($request, $handler);

        $seen = $handler->seen;
        self::assertNotNull($seen);
        $prefix = $seen->getAttribute(RequestAttributes::IP_PREFIX);
        self::assertInstanceOf(IpPrefix::class, $prefix);
        self::assertSame('203.0.113.0/24', (string) $prefix);
        $dump = json_encode([$seen->getHeaders(), $seen->getServerParams()], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('203.0.113.77', $dump);
        self::assertStringNotContainsString('10.0.0.5', $dump);
    }
}
