<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Shared\Log\IpScrubbingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogScrubbingTest extends TestCase
{
    public function testMasksIpv4AndIpv6(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, 'from 203.0.113.77 and 2001:db8:abcd:12::1', ['nested' => ['ip' => '::ffff:198.51.100.1', 'time' => '10:30:00']]);
        $scrubbed = new IpScrubbingProcessor()($record);
        self::assertSame('from [ip] and [ip]', $scrubbed->message);
        self::assertStringNotContainsString('198.51.100.1', json_encode($scrubbed->context, \JSON_THROW_ON_ERROR));
        self::assertSame('10:30:00', $scrubbed->context['nested']['time']);
    }

    public function testAuditMetadataRedaction(): void
    {
        $redacted = AuditLogger::redact(['password' => 'x', 'nested' => ['api_secret' => 'y', 'name' => 'ok'], 'public_key' => 'pk_1', 'totp_code' => '123']);
        self::assertSame(['password' => '[redacted]', 'nested' => ['api_secret' => '[redacted]', 'name' => 'ok'], 'public_key' => 'pk_1', 'totp_code' => '[redacted]'], $redacted);
    }
}
