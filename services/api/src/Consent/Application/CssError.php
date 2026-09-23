<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

/**
 * One problem in the custom CSS of a consent theme, at a 1-based line and column.
 */
final readonly class CssError implements \Stringable
{
    public function __construct(
        public int $line,
        public int $column,
        public string $message,
    ) {}

    /** The form used in `422 validation_failed` under `theme.css`. */
    public function __toString(): string
    {
        return \sprintf('Line %d, column %d: %s', $this->line, $this->column, $this->message);
    }
}
