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

    /**
     * Scrubs an event property *key* and holds the result to what the rollup can store.
     *
     * A key is validated at ingest and scrubbed afterwards, and scrubbing is a substitution: the
     * placeholder it leaves behind is not bound by the length the validated key had. Anything longer
     * than $max no longer fits `rollup_events_daily.prop_key`, and a key that cannot be inserted
     * fails the whole (site, day) rollup — for ever, since the day stays dirty. Such a key is
     * refused here instead, and the caller drops that one property.
     *
     * The placeholders also introduce `[` and `]`, which the ingest allow-list does not permit.
     * Those are kept deliberately: the rollup builds the JSON path with `JSON_QUOTE`
     * (`RawSelects::PROP_PATH`), which takes any key as data, so the brackets are storable and
     * telling the operator "[number]" is more useful than dropping the property.
     */
    public static function scrubPropKey(string $key, int $max): ?string
    {
        $scrubbed = self::scrub($key);

        return $scrubbed === '' || \strlen($scrubbed) > $max ? null : $scrubbed;
    }

    /** Paths: scrub segment by segment so slashes are never swallowed by the phone pattern. */
    public static function scrubPath(string $path): string
    {
        return implode('/', array_map(self::scrub(...), explode('/', $path)));
    }
}
