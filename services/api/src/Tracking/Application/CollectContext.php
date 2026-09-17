<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Net\IpPrefix;

final readonly class CollectContext
{
    public function __construct(
        public ?IpPrefix $ipPrefix,
        public string $userAgent,
        public string $acceptLanguage,
        public string $origin,
        public string $referer,
        public bool $doNotTrack,
        public bool $globalPrivacyControl,
    ) {}
}
