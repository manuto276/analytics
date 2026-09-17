<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

/**
 * One random salt per UTC day; the previous day's salt is destroyed at rotation so base-level
 * visitor hashes cannot be linked across days.
 */
interface DailySaltProvider
{
    /** 32-byte salt for the UTC day of $now (created on first use). */
    public function saltFor(\DateTimeImmutable $now): string;

    /** Ensures today's salt exists and deletes older ones. Returns the number of salts deleted. */
    public function rotate(\DateTimeImmutable $now): int;
}
