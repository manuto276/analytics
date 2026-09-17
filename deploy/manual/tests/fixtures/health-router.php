<?php

// Router for `php -S`: reports the commit of <HEALTH_DEPLOY_ROOT>/current like the real
// GET /api/v1/health, unless <HEALTH_CONTROL_DIR>/override.json forces {"code":…, "body":…}.

declare(strict_types=1);

header('Content-Type: application/json');
$override = getenv('HEALTH_CONTROL_DIR') . '/override.json';
if (is_file($override)) {
    $o = json_decode((string) file_get_contents($override), true);
    http_response_code((int) ($o['code'] ?? 200));
    echo is_string($o['body'] ?? null) ? $o['body'] : json_encode($o['body'] ?? []);

    return true;
}

clearstatcache(true);
$info = @file_get_contents(getenv('HEALTH_DEPLOY_ROOT') . '/current/BUILD_INFO.json');
if ($info === false) {
    http_response_code(503);
    echo json_encode(['status' => 'down']);

    return true;
}
$data = json_decode($info, true);
echo json_encode(['status' => 'ok', 'commit' => $data['commit'], 'version' => $data['version']]);

return true;
