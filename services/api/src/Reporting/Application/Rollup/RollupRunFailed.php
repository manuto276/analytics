<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Rollup;

use Analytics\Shared\Jobs\JobStats;

/**
 * Summary of a rollup run in which at least one (site, day) could not be built. The days that did
 * build are already committed and their dirty rows deleted; the failed ones stay dirty.
 */
final class RollupRunFailed extends \RuntimeException implements JobStats
{
    /**
     * @param array{days: int, sites: int, failed: int} $stats
     * @param list<array{site: int, day: string}>       $failures
     */
    public function __construct(
        public readonly array $stats,
        public readonly array $failures,
    ) {
        $listed = \array_slice($failures, 0, 5);
        $names = array_map(static fn(array $f): string => 'site ' . $f['site'] . ' day ' . $f['day'], $listed);
        parent::__construct(\sprintf(
            'Rollup build failed for %d day(s) (%s%s); %d day(s) rebuilt.',
            \count($failures),
            implode(', ', $names),
            \count($failures) > \count($listed) ? ', …' : '',
            $stats['days'],
        ));
    }

    /**
     * The days that did build are committed, so the run reports them even though it failed.
     *
     * @return array{days: int, sites: int, failed: int}
     */
    public function jobStats(): array
    {
        return $this->stats;
    }
}
