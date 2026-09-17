<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Shared\Crypto\KeyDerivation;
use Analytics\Shared\Crypto\SecretBox;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripWithAssociatedData(): void
    {
        $box = new SecretBox(['k1' => random_bytes(32)]);
        $cipher = $box->encrypt('JBSWY3DPEHPK3PXP', 'totp:1');
        self::assertStringStartsWith('v1.k1.', $cipher);
        self::assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($cipher, 'totp:1'));
        self::assertNotSame($cipher, $box->encrypt('JBSWY3DPEHPK3PXP', 'totp:1'), 'nonce must be random');

        $this->expectException(\RuntimeException::class);
        $box->decrypt($cipher, 'totp:2');
    }

    public function testRotationKeepsOldKeysReadable(): void
    {
        $old = random_bytes(32);
        $cipher = new SecretBox(['k1' => $old])->encrypt('secret');
        $rotated = new SecretBox(['k1' => $old, 'k2' => random_bytes(32)]);
        self::assertSame('k2', $rotated->activeKeyId());
        self::assertTrue($rotated->needsReencryption($cipher));
        self::assertSame('secret', $rotated->decrypt($cipher));
        self::assertFalse($rotated->needsReencryption($rotated->encrypt('secret')));
    }

    public function testTamperingAndUnknownKeysFail(): void
    {
        $box = new SecretBox(['k1' => random_bytes(32)]);
        $cipher = $box->encrypt('secret');
        foreach ([substr($cipher, 0, -2) . 'AA', 'v2.k1.abc', 'v1.k9.' . substr($cipher, 6), 'v1.k1.!!!', 'v1.k1.AAAA'] as $bad) {
            try {
                $box->decrypt($bad);
                self::fail('Expected failure for ' . $bad);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsBadKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox(['k1' => 'short']);
    }

    public function testTokensAndHashes(): void
    {
        $token = TokenGenerator::base64Url(32);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(32, \strlen(TokenHasher::hash($token)));
        self::assertTrue(TokenHasher::equals(TokenHasher::hash($token), $token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{21}$/', TokenGenerator::alphanumeric(21));
        self::assertSame("\x01\x02\xff", Base64Url::decode(Base64Url::encode("\x01\x02\xff")));
        self::assertNull(Base64Url::decode('a+b'));

        $kdf = new KeyDerivation(random_bytes(32));
        self::assertSame($kdf->derive('customer_ref', '1'), $kdf->derive('customer_ref', '1'));
        self::assertNotSame($kdf->derive('customer_ref', '1'), $kdf->derive('customer_ref', '2'));
    }
}
