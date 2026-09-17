<?php

declare(strict_types=1);

namespace Analytics\Conversions;

use Analytics\Kernel\Module;

final class ConversionsModule extends Module
{
    public function entityPaths(): array
    {
        return is_dir(__DIR__ . '/Domain') ? [__DIR__ . '/Domain'] : [];
    }
}
