<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Support;

/** `php -S` health endpoint reflecting <deploy root>/current, with an override switch. */
final class HealthServer
{
    /** @var resource */
    private $process;
    public readonly string $url;

    public function __construct(string $deployRoot, private readonly string $controlDir)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("cannot allocate port: $errstr");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $env = array_merge(getenv(), ['HEALTH_DEPLOY_ROOT' => $deployRoot, 'HEALTH_CONTROL_DIR' => $controlDir]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/health-router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $controlDir,
            $env,
        );
        $this->url = 'http://127.0.0.1:' . $port . '/api/v1/health';

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new \RuntimeException('health server did not start');
    }

    /** Forces the next responses (null body = JSON object with a wrong commit). */
    public function override(int $code, mixed $body = null): void
    {
        file_put_contents($this->controlDir . '/override.json', json_encode(['code' => $code, 'body' => $body ?? ['status' => 'ok', 'commit' => 'deadbeef']]));
    }

    public function clearOverride(): void
    {
        @unlink($this->controlDir . '/override.json');
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
