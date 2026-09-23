<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Psr\Log\AbstractLogger;

/** Keeps every record, for assertions on what was logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }
}
