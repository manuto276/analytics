<?php

declare(strict_types=1);

namespace Analytics\Shared\Net;

/**
 * A shortened IP address (IPv4 /24, IPv6 /48). The full address never leaves the ClientIp middleware.
 */
final readonly class IpPrefix implements \Stringable
{
    private function __construct(
        /** Packed network address (4 or 16 bytes) with host bits zeroed. */
        public string $packed,
        public int $bits,
    ) {}

    public static function fromPacked(string $packed, int $bits): self
    {
        if (!\in_array(\strlen($packed), [4, 16], true)) {
            throw new \InvalidArgumentException('Packed address must be 4 or 16 bytes.');
        }

        return new self($packed, $bits);
    }

    public function isV6(): bool
    {
        return \strlen($this->packed) === 16;
    }

    /** Printable network address without the length, e.g. 192.0.2.0 */
    public function address(): string
    {
        return (string) inet_ntop($this->packed);
    }

    public function __toString(): string
    {
        return $this->address() . '/' . $this->bits;
    }

    /**
     * Matches a configured exclusion such as "192.0.2.0/24", "192.0.2.0" or "2001:db8::/48".
     * The comparison uses the shorter of the two prefix lengths.
     */
    public function matches(string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        $network = @inet_pton($parts[0]);
        if ($network === false || \strlen($network) !== \strlen($this->packed)) {
            return false;
        }
        $maxBits = \strlen($network) * 8;
        $bits = isset($parts[1]) && ctype_digit($parts[1]) ? min((int) $parts[1], $maxBits) : $maxBits;
        $bits = min($bits, $this->bits);

        return IpTruncator::maskPacked($network, $bits) === IpTruncator::maskPacked($this->packed, $bits);
    }
}
