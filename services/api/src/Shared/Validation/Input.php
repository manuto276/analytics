<?php

declare(strict_types=1);

namespace Analytics\Shared\Validation;

use Analytics\Shared\Http\ApiProblem;

/**
 * Small typed accessor over a decoded JSON body or query array that collects field errors.
 * Call assertValid() after reading all fields.
 */
final class Input
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<array-key, mixed> $data */
    public function __construct(private readonly array $data, private readonly string $prefix = '') {}

    public static function fromBody(mixed $body): self
    {
        if ($body !== null && !\is_array($body)) {
            throw ApiProblem::badRequest('invalid_body', 'Expected a JSON object.');
        }

        return new self($body ?? []);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /** @return array<array-key, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function string(string $key, ?int $maxLength = 255, int $minLength = 1, ?string $pattern = null): string
    {
        $value = $this->optionalString($key, $maxLength, $minLength, $pattern);
        if ($value === null && !isset($this->errors[$this->path($key)])) {
            $this->error($key, 'This field is required.');
        }

        return $value ?? '';
    }

    public function optionalString(string $key, ?int $maxLength = 255, int $minLength = 0, ?string $pattern = null): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }
        $value = $this->data[$key];
        if (!\is_string($value)) {
            $this->error($key, 'Must be a string.');

            return null;
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $minLength) {
            $this->error($key, $minLength <= 1 ? 'This field is required.' : \sprintf('Must be at least %d characters.', $minLength));

            return null;
        }
        if ($maxLength !== null && $length > $maxLength) {
            $this->error($key, \sprintf('Must be at most %d characters.', $maxLength));

            return null;
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $this->error($key, 'Has an invalid format.');

            return null;
        }

        return $value;
    }

    /** Like string() but without trimming (passwords). */
    public function secret(string $key, int $minLength = 1, int $maxLength = 1024): string
    {
        $value = $this->data[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            $this->error($key, 'This field is required.');

            return '';
        }
        if (\strlen($value) < $minLength) {
            $this->error($key, \sprintf('Must be at least %d characters.', $minLength));
        } elseif (\strlen($value) > $maxLength) {
            $this->error($key, \sprintf('Must be at most %d characters.', $maxLength));
        }

        return $value;
    }

    public function email(string $key): string
    {
        $value = $this->string($key, 190);
        if ($value !== '' && filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
            $this->error($key, 'Must be a valid email address.');
        }

        return $value;
    }

    public function int(string $key, ?int $default = null, ?int $min = null, ?int $max = null): int
    {
        $value = $this->optionalInt($key, $min, $max);
        if ($value === null) {
            if ($default === null) {
                if (!isset($this->errors[$this->path($key)])) {
                    $this->error($key, 'This field is required.');
                }

                return 0;
            }

            return $default;
        }

        return $value;
    }

    public function optionalInt(string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            return null;
        }
        $value = $this->data[$key];
        if (\is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!\is_int($value)) {
            $this->error($key, 'Must be an integer.');

            return null;
        }
        if ($min !== null && $value < $min) {
            $this->error($key, \sprintf('Must be at least %d.', $min));

            return null;
        }
        if ($max !== null && $value > $max) {
            $this->error($key, \sprintf('Must be at most %d.', $max));

            return null;
        }

        return $value;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($default === null) {
                $this->error($key, 'This field is required.');

                return false;
            }

            return $default;
        }
        $value = $this->data[$key];
        if (\is_bool($value)) {
            return $value;
        }
        if (\in_array($value, ['1', 'true', 1], true)) {
            return true;
        }
        if (\in_array($value, ['0', 'false', 0], true)) {
            return false;
        }
        $this->error($key, 'Must be a boolean.');

        return $default ?? false;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public function enum(string $key, string $enum, ?\BackedEnum $default = null): ?\BackedEnum
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($default === null) {
                $this->error($key, 'This field is required.');
            }

            return $default;
        }
        $value = $this->data[$key];
        $case = \is_string($value) || \is_int($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            $allowed = array_map(static fn(\BackedEnum $c): string => (string) $c->value, $enum::cases());
            $this->error($key, 'Must be one of: ' . implode(', ', $allowed) . '.');

            return $default;
        }

        return $case;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, array $allowed, ?string $default = null): string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            if ($default === null) {
                $this->error($key, 'This field is required.');

                return '';
            }

            return $default;
        }
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            $this->error($key, 'Must be one of: ' . implode(', ', $allowed) . '.');

            return $default ?? '';
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key, int $maxItems = 100, int $maxLength = 255, ?string $pattern = null, bool $required = false): array
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($required) {
                $this->error($key, 'This field is required.');
            }

            return [];
        }
        $value = $this->data[$key];
        if (!\is_array($value) || !array_is_list($value)) {
            $this->error($key, 'Must be an array of strings.');

            return [];
        }
        if (\count($value) > $maxItems) {
            $this->error($key, \sprintf('Must contain at most %d items.', $maxItems));

            return [];
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!\is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > $maxLength || ($pattern !== null && preg_match($pattern, trim($item)) !== 1)) {
                $this->error($key . '.' . $i, 'Invalid value.');
                continue;
            }
            $out[] = trim($item);
        }

        return array_values(array_unique($out));
    }

    /** @return array<array-key, mixed>|null */
    public function array(string $key, bool $required = false): ?array
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($required) {
                $this->error($key, 'This field is required.');
            }

            return null;
        }
        if (!\is_array($this->data[$key])) {
            $this->error($key, 'Must be an object or array.');

            return null;
        }

        return $this->data[$key];
    }

    public function nested(string $key, bool $required = false): ?self
    {
        $value = $this->array($key, $required);

        return $value === null ? null : new self($value, $this->path($key) . '.');
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$this->path($key)][] = $message;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function merge(self $other): void
    {
        foreach ($other->errors as $key => $messages) {
            foreach ($messages as $message) {
                $this->errors[$key][] = $message;
            }
        }
    }

    public function assertValid(): void
    {
        if ($this->errors !== []) {
            throw ApiProblem::validation($this->errors);
        }
    }

    private function path(string $key): string
    {
        return $this->prefix . $key;
    }
}
