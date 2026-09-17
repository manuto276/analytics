<?php

declare(strict_types=1);

namespace Analytics\Consent;

use Analytics\Kernel\Module;

final class ConsentModule extends Module
{
    public function entityPaths(): array
    {
        return is_dir(__DIR__ . '/Domain') ? [__DIR__ . '/Domain'] : [];
    }
}
