<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

interface EventSink
{
    /** @param list<EventDraft> $drafts */
    public function accept(array $drafts): void;
}
