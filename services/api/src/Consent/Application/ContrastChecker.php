<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

/**
 * WCAG 2.x contrast ratio between two #rrggbb colours.
 */
final class ContrastChecker
{
    public const float MINIMUM = 4.5;

    public static function ratio(string $foreground, string $background): float
    {
        $l1 = self::luminance($foreground);
        $l2 = self::luminance($background);

        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            throw new \InvalidArgumentException('Colour must be #rrggbb.');
        }
        $channel = static function (string $component): float {
            $c = hexdec($component) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);
    }
}
