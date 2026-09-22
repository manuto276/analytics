#!/usr/bin/env php
<?php

// Fake per-release app console used by the deploy console tests.
// FAKE_APP_LOG: JSON line per invocation; FAKE_APP_FAIL: comma list of commands that exit 1;
// FAKE_APP_FAIL_FINAL: the same, but only once the release has its final name (not .tmp-*).

declare(strict_types=1);

$cmd = $argv[1] ?? '';
$log = getenv('FAKE_APP_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'cmd' => $cmd,
        'args' => array_slice($argv, 1),
        'cwd' => getcwd(),
        'release' => basename(dirname(__DIR__)),
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

$fail = array_filter(explode(',', (string) getenv('FAKE_APP_FAIL')));
if (!str_starts_with(basename(dirname(__DIR__)), '.tmp-')) {
    $fail = [...$fail, ...array_filter(explode(',', (string) getenv('FAKE_APP_FAIL_FINAL')))];
}
if (in_array($cmd, $fail, true)) {
    fwrite(STDERR, "fake {$cmd} failed\n");
    exit(1);
}
if ($cmd === 'app:preflight' && !is_file(dirname(__DIR__) . '/.env')) {
    fwrite(STDERR, "preflight: .env missing\n");
    exit(1);
}
echo "fake {$cmd} ok\n";
exit(0);
