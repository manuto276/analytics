<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

/**
 * Result of {@see CustomCssCompiler::compile()}: the rules to append to the banner stylesheet, or
 * the errors that prevent it (then `css` is empty).
 */
final readonly class CompiledCss
{
    /** @param list<CssError> $errors */
    public function __construct(
        public string $css,
        public array $errors = [],
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
