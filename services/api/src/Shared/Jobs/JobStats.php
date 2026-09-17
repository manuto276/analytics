<?php

declare(strict_types=1);

namespace Analytics\Shared\Jobs;

/**
 * A failure that still carries the counts the job produced before it gave up.
 *
 * A job that fails part of the way through (one poisoned row out of many) has usually done most of
 * its work, and the operator needs to see how much: JobRunner records these stats on the failed
 * `job_runs` row, so `jobs:status` and the admin jobs endpoint show the partial result instead of an
 * empty object and a message the counts had to be smuggled into.
 *
 * It lives in Shared so that any module's exception can opt in without Shared knowing about it.
 */
interface JobStats
{
    /** @return array<string, mixed> the same shape the job returns when it succeeds */
    public function jobStats(): array;
}
