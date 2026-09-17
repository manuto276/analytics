<?php

declare(strict_types=1);

namespace Analytics\Audit;

use Analytics\Kernel\Module;

final class AuditModule extends Module
{
    public function entityPaths(): array
    {
        return is_dir(__DIR__ . '/Domain') ? [__DIR__ . '/Domain'] : [];
    }
}
