<?php

declare(strict_types=1);

namespace Analytics\Health;

use Analytics\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;

final readonly class HealthController
{
    public function __construct(private HealthChecker $checker, private JsonResponder $responder) {}

    /** Public liveness/readiness: no details beyond status, version and commit (used by deploy health checks). */
    public function public(): ResponseInterface
    {
        $result = $this->checker->run();
        $body = ['status' => $result['status']] + $this->checker->buildInfo();

        return $this->responder->json($body, $result['status'] === 'fail' ? 503 : 200)->withHeader('Cache-Control', 'no-store');
    }

    public function detailed(): ResponseInterface
    {
        $result = $this->checker->run();

        return $this->responder->data($result + $this->checker->buildInfo());
    }
}
