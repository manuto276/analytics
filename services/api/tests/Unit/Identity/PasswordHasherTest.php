<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Identity;

use Analytics\Identity\Application\Authorizer;
use Analytics\Identity\Application\PasswordHasher;
use Analytics\Identity\Application\Permission;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Identity\Domain\User;
use Analytics\Identity\Domain\UserStatus;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testArgon2idHashAndVerify(): void
    {
        $hasher = new PasswordHasher(true);
        $hash = $hasher->hash('correct horse battery staple');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
    }

    public function testPolicy(): void
    {
        self::assertNotSame([], PasswordHasher::validatePolicy('short'));
        self::assertNotSame([], PasswordHasher::validatePolicy('aaaaaaaaaaaaaaaa'));
        self::assertNotSame([], PasswordHasher::validatePolicy('mario.rossi-1234', 'mario.rossi@example.com'));
        self::assertSame([], PasswordHasher::validatePolicy('correct horse battery staple', 'x@example.com'));
    }

    public function testAuthorizerMatrix(): void
    {
        $now = new \DateTimeImmutable();
        $admin = new User('a@example.com', 'x', 'A', GlobalRole::Admin, $now);
        $member = new User('m@example.com', 'x', 'M', GlobalRole::Member, $now);

        self::assertTrue(Authorizer::allows(Permission::ADMIN, $admin, SiteRole::Admin));
        self::assertFalse(Authorizer::allows(Permission::ADMIN, $member, SiteRole::Admin));
        self::assertTrue(Authorizer::allows(Permission::SITE_VIEW, $member, SiteRole::Viewer));
        self::assertFalse(Authorizer::allows(Permission::SITE_VIEW, $member, null));
        self::assertFalse(Authorizer::allows(Permission::SITE_MANAGE, $member, SiteRole::Viewer));
        self::assertTrue(Authorizer::allows(Permission::SITE_MANAGE, $member, SiteRole::Admin));
        self::assertTrue(Authorizer::allows(Permission::AUTHENTICATED, $member, null));
        self::assertFalse(Authorizer::allows('unknown', $admin, SiteRole::Admin));

        $member->status = UserStatus::Disabled;
        self::assertFalse(Authorizer::allows(Permission::AUTHENTICATED, $member, null));
    }
}
