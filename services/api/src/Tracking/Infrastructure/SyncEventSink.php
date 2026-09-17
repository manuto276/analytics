<?php

declare(strict_types=1);

namespace Analytics\Tracking\Infrastructure;

use Analytics\Tracking\Application\EventSink;
use Analytics\Tracking\Application\IngestBatchHandler;

/** INGEST_MODE=sync: writes within the request. */
final readonly class SyncEventSink implements EventSink
{
    public function __construct(private IngestBatchHandler $handler) {}

    public function accept(array $drafts): void
    {
        if ($drafts !== []) {
            $this->handler->handle($drafts);
        }
    }
}
