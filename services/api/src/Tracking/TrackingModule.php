<?php

declare(strict_types=1);

namespace Analytics\Tracking;

use Analytics\Kernel\Module;

final class TrackingModule extends Module
{
    public function entityPaths(): array
    {
        return is_dir(__DIR__ . '/Domain') ? [__DIR__ . '/Domain'] : [];
    }
}
