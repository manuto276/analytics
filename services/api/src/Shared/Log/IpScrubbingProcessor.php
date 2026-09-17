<?php

declare(strict_types=1);

namespace Analytics\Shared\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Defence in depth: masks anything that looks like an IP address in log messages and context.
 */
final class IpScrubbingProcessor implements ProcessorInterface
{
    private const string V4 = '/\b(?:\d{1,3}\.){3}\d{1,3}\b/';
    /** Candidate IPv6 tokens (validated with inet_pton before masking). */
    private const string V6 = '/(?<![0-9A-Za-z:.])[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*(?![0-9A-Za-z:])/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrub($record->message),
            context: self::scrubArray($record->context),
            extra: self::scrubArray($record->extra),
        );
    }

    public static function scrub(string $value): string
    {
        $value = (string) preg_replace_callback(self::V6, static fn(array $m): string => @inet_pton(rtrim($m[0], '.')) !== false ? '[ip]' : $m[0], $value);

        return (string) preg_replace(self::V4, '[ip]', $value);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $data[$key] = self::scrub($value);
            } elseif (\is_array($value)) {
                $data[$key] = self::scrubArray($value);
            }
        }

        return $data;
    }
}
