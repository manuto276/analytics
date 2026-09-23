<?php

declare(strict_types=1);

namespace Analytics\Consent\Domain;

/**
 * How the floating reopen button looks on one device class.
 */
final readonly class ReopenPlacement
{
    public function __construct(
        public string $variant,
        public string $position,
        public int $offset,
    ) {}

    /** @return array{variant: string, position: string, offset: int} */
    public function toArray(): array
    {
        return ['variant' => $this->variant, 'position' => $this->position, 'offset' => $this->offset];
    }
}
