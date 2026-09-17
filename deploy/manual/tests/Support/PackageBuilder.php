<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Support;

/** Builds fake analytics release packages (tar.gz + .sha256). */
final class PackageBuilder
{
    /** @var array<string, array{type: string, content: string, mode: int}> */
    private array $members = [];
    /** @var array<string, mixed> */
    private array $manifest;
    private ?string $revision = null;
    private ?string $checksum = null;
    /** @var list<array{string, string, string}> raw extra members appended after the regular ones */
    private array $raw = [];

    public function __construct(public readonly string $ts, public readonly string $commit)
    {
        $this->manifest = [
            'name' => 'analytics',
            'version' => $ts,
            'commit' => $commit,
            'commit_date' => '2026-09-01T10:00:00Z',
            'built_at' => '2026-09-01T10:05:00Z',
            'php' => '>=8.4.1',
            'extensions' => ['json'],
            'migrations' => ['Version20260101000000'],
            'tracker_sha256' => str_repeat('a', 64),
        ];
        $this->dir('bin');
        $this->file('bin/analytics', (string) file_get_contents(__DIR__ . '/../fixtures/fake-analytics.php'), 0755);
        $this->dir('public');
        $this->file('public/index.php', "<?php echo 'app';\n");
        $this->file('public/index.html', "<!doctype html><title>analytics</title>\n");
        $this->file('.env.example', "APP_ENV=prod\nDATABASE_URL=mysql://user:pass@127.0.0.1:3306/analytics\n");
        $this->file('LICENSE', "AGPL-3.0-or-later\n");
        $this->dir('config');
        $this->file('config/app.php', "<?php return [];\n");
        $this->dir('var');
        $this->dir('var/cache');
        $this->dir('deploy');
        $this->file('deploy/console', (string) file_get_contents(DeployTestCase::consoleSource()), 0755);
    }

    public static function make(string $ts, ?string $commit = null): self
    {
        return new self($ts, $commit ?? sha1($ts . random_bytes(4)));
    }

    public function file(string $name, string $content, int $mode = 0644): self
    {
        $this->members[$name] = ['type' => 'file', 'content' => $content, 'mode' => $mode];

        return $this;
    }

    public function dir(string $name): self
    {
        $this->members[$name] = ['type' => 'dir', 'content' => '', 'mode' => 0755];

        return $this;
    }

    public function symlink(string $name, string $target): self
    {
        $this->members[$name] = ['type' => 'symlink', 'content' => $target, 'mode' => 0777];

        return $this;
    }

    public function without(string $name): self
    {
        unset($this->members[$name]);

        return $this;
    }

    /** Appends a member verbatim (no normalisation), e.g. "../evil" or "/etc/evil". */
    public function raw(string $type, string $name, string $contentOrTarget = 'x'): self
    {
        $this->raw[] = [$type, $name, $contentOrTarget];

        return $this;
    }

    /** @param array<string, mixed> $values */
    public function manifest(array $values): self
    {
        $this->manifest = array_merge($this->manifest, $values);

        return $this;
    }

    public function revision(string $revision): self
    {
        $this->revision = $revision;

        return $this;
    }

    public function migrations(string ...$migrations): self
    {
        $this->manifest['migrations'] = array_values($migrations);

        return $this;
    }

    public function wrongChecksum(): self
    {
        $this->checksum = str_repeat('0', 64);

        return $this;
    }

    public function name(): string
    {
        return 'analytics-' . $this->ts . '.tar.gz';
    }

    /** Writes the package and its .sha256 into $dir; returns the package path. */
    public function build(string $dir): string
    {
        $tar = new TarWriter();
        $tar->dir('./');
        $tar->file('./BUILD_INFO.json', json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $tar->file('./REVISION', ($this->revision ?? $this->commit) . "\n");
        foreach ($this->members as $name => $m) {
            match ($m['type']) {
                'file' => $tar->file('./' . $name, $m['content'], $m['mode']),
                'dir' => $tar->dir('./' . $name),
                'symlink' => $tar->symlink('./' . $name, $m['content']),
            };
        }
        foreach ($this->raw as [$type, $name, $value]) {
            match ($type) {
                'file' => $tar->file($name, $value),
                'dir' => $tar->dir($name),
                'symlink' => $tar->symlink($name, $value),
            };
        }
        $path = rtrim($dir, '/') . '/' . $this->name();
        file_put_contents($path, $tar->gz());
        $sum = $this->checksum ?? hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $sum . '  ' . $this->name() . "\n");

        return $path;
    }
}
