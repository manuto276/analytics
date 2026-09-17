<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Support;

final class RunResult
{
    public function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }
}
