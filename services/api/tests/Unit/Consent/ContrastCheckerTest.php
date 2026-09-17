<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Consent;

use Analytics\Consent\Application\ContrastChecker;
use PHPUnit\Framework\TestCase;

final class ContrastCheckerTest extends TestCase
{
    public function testRatios(): void
    {
        self::assertSame(21.0, ContrastChecker::ratio('#000000', '#ffffff'));
        self::assertSame(21.0, ContrastChecker::ratio('#FFFFFF', '#000000'), 'order does not matter');
        self::assertSame(1.0, ContrastChecker::ratio('#123456', '#123456'));
        self::assertGreaterThanOrEqual(ContrastChecker::MINIMUM, ContrastChecker::ratio('#111827', '#ffffff'));
        self::assertLessThan(ContrastChecker::MINIMUM, ContrastChecker::ratio('#999999', '#aaaaaa'));
    }

    public function testRejectsMalformedColours(): void
    {
        foreach (['fff', '#ff', 'red', '#12345g'] as $colour) {
            try {
                ContrastChecker::ratio($colour, '#ffffff');
                self::fail('expected rejection of ' . $colour);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('#rrggbb', $e->getMessage());
            }
        }
    }
}
