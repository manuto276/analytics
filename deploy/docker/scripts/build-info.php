<?php

declare(strict_types=1);

/*
 * Writes BUILD_INFO.json and REVISION into an assembled release tree
 * (deploy/docker/Dockerfile, stage `release-tree`).
 *
 * Usage: php build-info.php <release-dir> <build-ts> <commit> [commit-date]
 *
 * BUILD_INFO.json is the manifest the deploy console (deploy/manual/console) verifies:
 *   name, version, commit, commit_date, built_at, php, extensions, migrations, tracker_sha256
 */

$releaseDir = $argv[1] ?? '';
$version = $argv[2] ?? '';
$commit = $argv[3] ?? '';
$commitDate = $argv[4] ?? '';

if ($releaseDir === '' || $version === '' || $commit === '') {
    fwrite(STDERR, "Usage: php build-info.php <release-dir> <build-ts> <commit> [commit-date]\n");
    exit(2);
}
if (!is_dir($releaseDir)) {
    fwrite(STDERR, sprintf("build-info: %s is not a directory\n", $releaseDir));
    exit(1);
}

/** Required PHP version and extensions, straight from the application's composer.json. */
$php = '>=8.4.1';
$extensions = [];
$composerFile = $releaseDir . '/composer.json';
if (is_file($composerFile)) {
    $composer = json_decode((string) file_get_contents($composerFile), true, 32, JSON_THROW_ON_ERROR);
    foreach ((array) ($composer['require'] ?? []) as $package => $constraint) {
        if ($package === 'php' && is_string($constraint) && $constraint !== '') {
            $php = $constraint;
        } elseif (str_starts_with((string) $package, 'ext-')) {
            $extensions[] = substr((string) $package, 4);
        }
    }
}
sort($extensions);
$extensions = array_values(array_unique($extensions));

/** Migration class names, so the console can compare releases on rollback. */
$migrations = [];
foreach (glob($releaseDir . '/migrations/Version*.php') ?: [] as $file) {
    $migrations[] = basename($file, '.php');
}
sort($migrations);

$trackerFile = $releaseDir . '/resources/tracker/tracker.js';
if (!is_file($trackerFile)) {
    fwrite(STDERR, "build-info: resources/tracker/tracker.js is missing from the release tree\n");
    exit(1);
}

$manifest = [
    'name' => 'analytics',
    'version' => $version,
    'commit' => $commit,
    'commit_date' => $commitDate,
    'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'php' => $php,
    'extensions' => $extensions,
    'migrations' => $migrations,
    'tracker_sha256' => hash_file('sha256', $trackerFile),
];

file_put_contents(
    $releaseDir . '/BUILD_INFO.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
file_put_contents($releaseDir . '/REVISION', $commit . "\n");

printf(
    "build-info: version %s, commit %s, %d migration(s), extensions: %s\n",
    $version,
    substr($commit, 0, 12),
    count($migrations),
    implode(', ', $extensions),
);
