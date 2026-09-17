<?php

declare(strict_types=1);

namespace Analytics\Shared\Net;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the client address honouring X-Forwarded-For only from trusted proxies.
 */
final readonly class ClientIpResolver
{
    /** @param list<string> $trustedProxies */
    public function __construct(private array $trustedProxies) {}

    public function resolve(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $remote = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null;
        if ($remote === null) {
            return null;
        }
        if (!$this->isTrusted($remote)) {
            return $remote;
        }
        $header = $request->getHeaderLine('X-Forwarded-For');
        if ($header === '') {
            return $remote;
        }
        $chain = array_reverse(array_values(array_filter(array_map('trim', explode(',', $header)), static fn(string $v): bool => $v !== '')));
        foreach ($chain as $hop) {
            if (@inet_pton(trim($hop, '[]')) === false) {
                return $remote;
            }
            if (!$this->isTrusted($hop)) {
                return trim($hop, '[]');
            }
        }

        return $chain === [] ? $remote : trim($chain[\count($chain) - 1], '[]');
    }

    private function isTrusted(string $ip): bool
    {
        $packed = @inet_pton(trim($ip, '[]'));
        if ($packed === false) {
            return false;
        }
        foreach ($this->trustedProxies as $cidr) {
            $parts = explode('/', $cidr, 2);
            $network = @inet_pton($parts[0]);
            if ($network === false || \strlen($network) !== \strlen($packed)) {
                continue;
            }
            $bits = isset($parts[1]) ? (int) $parts[1] : \strlen($network) * 8;
            if (IpTruncator::maskPacked($network, $bits) === IpTruncator::maskPacked($packed, $bits)) {
                return true;
            }
        }

        return false;
    }
}
