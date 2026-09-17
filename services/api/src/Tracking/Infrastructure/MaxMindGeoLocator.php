<?php

declare(strict_types=1);

namespace Analytics\Tracking\Infrastructure;

use Analytics\Shared\Net\IpPrefix;
use Analytics\Tracking\Application\GeoLocator;
use MaxMind\Db\Reader;

/**
 * Country lookup in the DB-IP Lite Country database (CC BY 4.0), using only the shortened address.
 * Works without the file (returns null).
 */
final class MaxMindGeoLocator implements GeoLocator
{
    private ?Reader $reader = null;
    /** Set only when the file exists but cannot be opened, to avoid retrying a corrupt database. */
    private bool $broken = false;
    /** @var array<string, ?string> */
    private array $memo = [];

    public function __construct(private readonly string $path) {}

    public function country(?IpPrefix $ip): ?string
    {
        if ($ip === null || $this->broken) {
            return null;
        }
        $key = $ip->packed;
        if (\array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        if (\count($this->memo) > 5000) {
            $this->memo = [];
        }
        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }
        try {
            $record = $reader->get($ip->address());
        } catch (\Throwable) {
            $record = null;
        }
        $code = null;
        if (\is_array($record)) {
            $country = $record['country'] ?? $record['registered_country'] ?? null;
            $iso = \is_array($country) ? ($country['iso_code'] ?? null) : null;
            $code = \is_string($iso) && preg_match('/^[A-Z]{2}$/', $iso) === 1 ? $iso : null;
        }

        return $this->memo[$key] = $code;
    }

    private function reader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }
        // The file may be installed later by geo:update, so its absence is never cached.
        if (!is_file($this->path)) {
            return null;
        }
        try {
            return $this->reader = new Reader($this->path);
        } catch (\Throwable) {
            $this->broken = true;

            return null;
        }
    }
}
