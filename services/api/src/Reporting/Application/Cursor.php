<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Shared\Http\ApiProblem;

/**
 * Opaque offset cursor for table reports.
 */
final class Cursor
{
    public const int MAX_OFFSET = 100_000;

    public static function encode(int $offset): string
    {
        return Base64Url::encode('o' . $offset);
    }

    public static function decode(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $raw = Base64Url::decode($cursor);
        if ($raw === null || preg_match('/^o(\d{1,6})$/', $raw, $m) !== 1 || (int) $m[1] > self::MAX_OFFSET) {
            throw ApiProblem::validation(['cursor' => ['Invalid cursor.']]);
        }

        return (int) $m[1];
    }
}
