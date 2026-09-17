<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Net\IpPrefix;

interface GeoLocator
{
    /** ISO 3166-1 alpha-2 country code of a shortened address, or null. */
    public function country(?IpPrefix $ip): ?string;
}
