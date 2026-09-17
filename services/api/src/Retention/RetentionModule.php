<?php

declare(strict_types=1);

namespace Analytics\Retention;

use Analytics\Kernel\Module;

final class RetentionModule extends Module
{
    public function commands(): array
    {
        return [
            Console\PartitionsMaintainCommand::class,
            Console\RetentionPurgeCommand::class,
        ];
    }
}
