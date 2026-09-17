<?php

declare(strict_types=1);

namespace Analytics\Audit\Application;

final readonly class Actor
{
    public function __construct(
        /** user | api_key | console | system */
        public string $type,
        public ?int $id,
        public ?string $ipPrefix = null,
    ) {}

    public static function console(): self
    {
        return new self('console', null);
    }

    public static function system(): self
    {
        return new self('system', null);
    }
}
