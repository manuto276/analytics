<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Deploy;

use AnalyticsDeploy\Tests\Support\DeployTestCase;
use AnalyticsDeploy\Tests\Support\PackageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class PackageVerificationTest extends DeployTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->init();
    }

    private function assertRejected(PackageBuilder $pkg, string $expected): void
    {
        $r = $this->deploy($pkg);
        self::assertSame(1, $r->exit, $r->output());
        self::assertStringContainsString($expected, $r->stderr);
        self::assertNull($this->currentTarget(), 'current must not be created');
        self::assertSame([], $this->releaseDirs(), 'no release or tmp dir may remain');
        self::assertFileDoesNotExist($this->root . '/evil');
        self::assertFileDoesNotExist($this->root . '/releases/evil');
        self::assertSame([], $this->appCalls(), 'bin/analytics must not run');
        $last = $this->history()[array_key_last($this->history())];
        self::assertSame('failed', $last['result']);
    }

    public function testChecksumMismatchIsRejected(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->wrongChecksum(), 'Checksum mismatch');
    }

    public function testMissingChecksumFileIsRejected(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $path = $this->addPackage($pkg);
        unlink($path . '.sha256');
        $r = $this->console(['deploy']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('.sha256 not found', $r->stderr);
    }

    public function testTamperedPackageIsRejected(): void
    {
        $pkg = PackageBuilder::make(self::ts(1));
        $path = $this->addPackage($pkg);
        file_put_contents($path, 'x', FILE_APPEND);
        $r = $this->console(['deploy']);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('Checksum mismatch', $r->stderr);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function unsafeMembers(): iterable
    {
        yield 'parent traversal' => ['file', '../evil', 'x', 'path traversal'];
        yield 'nested traversal' => ['file', 'public/../../evil', 'x', 'path traversal'];
        yield 'absolute path' => ['file', '/tmp/analytics-deploy-evil', 'x', 'absolute path'];
        yield 'absolute symlink' => ['symlink', 'public/etc', '/etc', 'absolute or empty target'];
        yield 'escaping symlink' => ['symlink', 'public/up', '../../..', 'escapes the package'];
        yield 'escaping symlink via other symlink' => ['symlink', 'public/a/b/deep', '../../../..', 'escapes the package'];
    }

    #[DataProvider('unsafeMembers')]
    public function testUnsafeMembersAreRejected(string $type, string $name, string $value, string $expected): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->raw($type, $name, $value), $expected);
        self::assertFileDoesNotExist('/tmp/analytics-deploy-evil');
    }

    public function testSymlinkChainThatOnlyEscapesWhenResolvedIsRejected(): void
    {
        // public/x/c -> ../.. resolves to the root (fine on its own), but public/x/a -> c/..
        // looks harmless lexically (public/x) while really resolving outside the release.
        $pkg = PackageBuilder::make(self::ts(1))
            ->dir('public/x')
            ->symlink('public/x/c', '../..')
            ->symlink('public/x/a', 'c/..');
        $this->assertRejected($pkg, 'escapes the package');
    }

    public function testFileWrittenThroughEscapingSymlinkIsRejected(): void
    {
        $pkg = PackageBuilder::make(self::ts(1))
            ->symlink('link', 'public/../..')
            ->raw('file', 'link/evil', 'x');
        $this->assertRejected($pkg, 'Unsafe');
    }

    public function testInternalSymlinkIsAllowed(): void
    {
        $pkg = PackageBuilder::make(self::ts(1))->symlink('public/app.html', 'index.html');
        $this->assertDeployOk($this->deploy($pkg));
        self::assertSame('index.html', readlink($this->root . '/current/public/app.html'));
    }

    public function testVersionMismatch(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->manifest(['version' => self::ts(2)]), 'does not match the package timestamp');
    }

    public function testRevisionMismatch(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->revision(str_repeat('b', 40)), 'REVISION');
    }

    public function testPhpConstraintUnmet(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->manifest(['php' => '>=99.0.0']), 'does not satisfy the package requirement ">=99.0.0"');
    }

    public function testMissingExtension(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->manifest(['extensions' => ['json', 'no_such_ext_xyz']]), 'missing required extension(s): no_such_ext_xyz');
    }

    public function testExtensionNamesAreMatchedLikePreflight(): void
    {
        $extensions = ['JSON', 'Core'];
        if (\extension_loaded('Zend OPcache')) {
            $extensions[] = 'opcache';
        }
        $this->assertDeployOk($this->deploy(PackageBuilder::make(self::ts(1))->manifest(['extensions' => $extensions])));
    }

    public function testWrongName(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->manifest(['name' => 'other']), 'expected "analytics"');
    }

    public function testMissingRequiredMember(): void
    {
        $this->assertRejected(PackageBuilder::make(self::ts(1))->without('bin/analytics'), 'missing required file "bin/analytics"');
    }

    public function testInvalidPackageNameIsUsageError(): void
    {
        $r = $this->console(['deploy', 'analytics-latest.tar.gz']);
        self::assertSame(2, $r->exit);
        self::assertStringContainsString('Invalid package name', $r->stderr);
    }

    public function testVerifyCommand(): void
    {
        $good = PackageBuilder::make(self::ts(1));
        $this->addPackage($good);
        $before = $this->snapshot($this->root);
        $r = $this->console(['verify', $good->name()]);
        self::assertSame(0, $r->exit, $r->output());
        self::assertStringContainsString('is valid (commit ' . $good->commit, $r->stdout);
        self::assertSame($before, $this->snapshot($this->root));

        $bad = PackageBuilder::make(self::ts(2))->manifest(['php' => '<8.0']);
        $this->addPackage($bad);
        $r = $this->console(['verify', self::ts(2)]);
        self::assertSame(1, $r->exit);
        self::assertStringContainsString('does not satisfy', $r->stderr);

        self::assertSame(2, $this->console(['verify'])->exit);
    }

    public function testPharDataFallbackExtracts(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('phar extension not available');
        }
        $pkg = PackageBuilder::make(self::ts(1))->dir('public/empty')->symlink('public/app.html', 'index.html');
        $this->assertDeployOk($this->deploy($pkg, [], ['ANALYTICS_DEPLOY_TAR' => 'phar']));
        self::assertSame('index.html', readlink($this->root . '/current/public/app.html'));
        self::assertDirectoryExists($this->root . '/current/public/empty');
        self::assertSame(0750, fileperms($this->root . '/current/bin/analytics') & 0777);
        self::assertFileExists($this->root . '/current/bin/analytics');
        self::assertFileExists($this->root . '/current/public/index.php');
    }
}
