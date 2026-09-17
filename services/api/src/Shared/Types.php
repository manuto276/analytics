<?php

declare(strict_types=1);

namespace Analytics\Shared;

/**
 * Checked conversions for values typed as mixed (database rows, console options, decoded JSON).
 */
final class Types
{
    public static function string(mixed $value, string $default = ''): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            $value === null => $default,
            default => throw new \UnexpectedValueException('Expected a scalar, got ' . get_debug_type($value)),
        };
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && is_numeric($value) => (int) $value,
            \is_float($value) => (int) $value,
            \is_bool($value) => $value ? 1 : 0,
            $value === null => $default,
            default => throw new \UnexpectedValueException('Expected an integer, got ' . get_debug_type($value)),
        };
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return match (true) {
            \is_float($value), \is_int($value) => (float) $value,
            \is_string($value) && is_numeric($value) => (float) $value,
            $value === null => $default,
            default => throw new \UnexpectedValueException('Expected a number, got ' . get_debug_type($value)),
        };
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(self::string(...), array_filter($value, static fn(mixed $v): bool => \is_scalar($v))));
    }
}
