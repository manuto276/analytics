<?php

declare(strict_types=1);

/*
 * Test-only bridge between the Playwright container and `bin/analytics`.
 *
 * The end-to-end suite runs inside the pinned Playwright image, which has no
 * docker client, but several scenarios need operator commands (rollup:run,
 * api-key:create, invitation:create, dev:seed …). This tiny HTTP service runs
 * in the compose test stack next to the application, on the internal network
 * only, and executes an allow-listed command.
 *
 *   docker compose … run:  php -S 0.0.0.0:8099 /e2e/support/console-bridge.php
 *   POST /run  {"command": "rollup:run", "args": ["--site=1"]}
 *        → 200 {"exit": 0, "stdout": "…", "stderr": "…"}
 *
 * It exists only in deploy/docker/compose.test.yml (APP_ENV=test, no published
 * port, no authentication because it is unreachable from outside the stack).
 * It is never part of a release: nothing outside services/e2e references it.
 */

const ALLOWED_COMMANDS = [
    'api-key:create',
    'api-key:revoke',
    'cache:clear',
    'conversions:reattribute',
    'dev:seed',
    'geo:lookup',
    'health:check',
    'invitation:create',
    'jobs:status',
    'migrations:migrate',
    'partitions:maintain',
    'retention:purge',
    'rollup:rebuild',
    'rollup:run',
    'salt:rotate',
    'site:create',
    'site:domain:add',
    'site:domain:remove',
    'site:list',
    'site:show',
    'user:create-admin',
    'user:disable',
    'user:list',
    'user:reset-2fa',
    'user:set-password',
];

const PROJECT_DIR = '/app';

function json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/health') {
    json(200, ['status' => 'ok', 'commands' => ALLOWED_COMMANDS]);

    return;
}

if ($path !== '/run' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json(404, ['error' => 'POST /run or GET /health']);

    return;
}

try {
    $body = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    json(400, ['error' => 'invalid JSON: ' . $e->getMessage()]);

    return;
}

$command = is_array($body) && is_string($body['command'] ?? null) ? $body['command'] : '';
$args = is_array($body) && is_array($body['args'] ?? null) ? array_values($body['args']) : [];
$stdin = is_array($body) && is_string($body['stdin'] ?? null) ? $body['stdin'] : '';

if (!in_array($command, ALLOWED_COMMANDS, true)) {
    json(400, ['error' => sprintf('command "%s" is not allow-listed', $command)]);

    return;
}
foreach ($args as $arg) {
    if (!is_string($arg) || preg_match('/^[-\w@.:=+*\/,\[\]{}" ]{0,200}$/u', $arg) !== 1) {
        json(400, ['error' => 'arguments may only contain [-\w@.:=+*/,[]{}" ] characters']);

        return;
    }
}

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    array_merge([PHP_BINARY, PROJECT_DIR . '/bin/analytics', $command], $args),
    $descriptors,
    $pipes,
    PROJECT_DIR,
);

if (!is_resource($process)) {
    json(500, ['error' => 'could not start bin/analytics']);

    return;
}

if ($stdin !== '') {
    fwrite($pipes[0], $stdin);
}
fclose($pipes[0]);
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);

json(200, [
    'command' => $command,
    'args' => $args,
    'exit' => $exit,
    'stdout' => $stdout,
    'stderr' => $stderr,
]);
