<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

/**
 * Removes obvious personal data from paths, query values and event properties.
 */
final class PiiScrubber
{
    private const array PATTERNS = [
        '/[A-Za-z0-9._%+\-]+(?:@|%40)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        '/\d{9,}/' => '[number]',
        '/(?<![\w])(?:\+\d[\d \-().]{7,}\d|\(?\d{2,5}\)?[ \-.]\d[\d \-.]{5,}\d)(?![\w])/' => '[phone]',
    ];

    public static function scrub(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /** Paths: scrub segment by segment so slashes are never swallowed by the phone pattern. */
    public static function scrubPath(string $path): string
    {
        return implode('/', array_map(self::scrub(...), explode('/', $path)));
    }
}
