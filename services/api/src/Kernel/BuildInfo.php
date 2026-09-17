<?php

declare(strict_types=1);

namespace Analytics\Kernel;

/**
 * Version and commit of the running release (from BUILD_INFO.json in packaged releases).
 */
final readonly class BuildInfo
{
    public function __construct(
        public string $version,
        public string $commit,
        public ?string $builtAt,
    ) {}

    public static function load(string $projectDir): self
    {
        foreach ([$projectDir . '/BUILD_INFO.json', \dirname($projectDir, 2) . '/BUILD_INFO.json'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (\is_array($data)) {
                return new self(
                    version: \is_string($data['version'] ?? null) ? $data['version'] : 'dev',
                    commit: \is_string($data['commit'] ?? null) ? $data['commit'] : 'unknown',
                    builtAt: \is_string($data['built_at'] ?? null) ? $data['built_at'] : null,
                );
            }
        }

        $commit = 'unknown';
        $revision = $projectDir . '/REVISION';
        if (is_file($revision)) {
            $commit = trim((string) file_get_contents($revision));
        } else {
            $head = \dirname($projectDir, 2) . '/.git/HEAD';
            if (is_file($head)) {
                $ref = trim((string) file_get_contents($head));
                if (str_starts_with($ref, 'ref: ')) {
                    $refFile = \dirname($projectDir, 2) . '/.git/' . substr($ref, 5);
                    $commit = is_file($refFile) ? trim((string) file_get_contents($refFile)) : 'unknown';
                } else {
                    $commit = $ref;
                }
            }
        }

        return new self('dev', $commit, null);
    }
}
