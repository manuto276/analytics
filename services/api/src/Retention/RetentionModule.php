<?php

declare(strict_types=1);

namespace Analytics\Retention;

use Analytics\Kernel\Module;

final class RetentionModule extends Module
{
    public function entityPaths(): array
    {
        return is_dir(__DIR__ . '/Domain') ? [__DIR__ . '/Domain'] : [];
    }
}
