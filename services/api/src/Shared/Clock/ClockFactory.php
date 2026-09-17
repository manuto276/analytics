<?php

declare(strict_types=1);

namespace Analytics\Shared\Clock;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class ClockFactory
{
    /**
     * APP_TEST_CLOCK (only honoured in APP_ENV=test) freezes time, e.g. "2026-09-17T10:00:00Z".
     */
    public static function create(?string $testClock): ClockInterface
    {
        if ($testClock !== null && $testClock !== '') {
            return new MockClock(new \DateTimeImmutable($testClock, new \DateTimeZone('UTC')), 'UTC');
        }

        return new NativeClock('UTC');
    }
}
