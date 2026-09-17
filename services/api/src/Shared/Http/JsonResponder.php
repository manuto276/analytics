<?php

declare(strict_types=1);

namespace Analytics\Shared\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class JsonResponder
{
    public const int FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public function data(array $data, int $status = 200): ResponseInterface
    {
        return $this->json(['data' => $data], $status);
    }

    public function noContent(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    public function problem(ApiProblem $problem, ?string $requestId = null): ResponseInterface
    {
        $body = [
            'type' => 'https://github.com/manuto276/analytics/blob/main/docs/api/errors.md#' . $problem->type,
            'title' => $problem->title(),
            'status' => $problem->status,
            'code' => $problem->type,
        ];
        if ($problem->detail !== null) {
            $body['detail'] = $problem->detail;
        }
        if ($problem->errors !== []) {
            $body['errors'] = $problem->errors;
        }
        if ($requestId !== null) {
            $body['request_id'] = $requestId;
        }
        $body += $problem->extra;
        $response = $this->responseFactory->createResponse($problem->status)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write(json_encode($body, self::FLAGS));

        return $response;
    }

    public function csv(string $csv, string $filename): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $response->getBody()->write($csv);

        return $response;
    }
}
