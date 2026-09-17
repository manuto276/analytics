<?php

declare(strict_types=1);

namespace Analytics\Shared\Http;

/**
 * An error rendered as RFC 9457 problem details.
 */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors   field path => messages
     * @param array<string, mixed>        $extra    extension members
     * @param array<string, string>       $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($title, $status);
    }

    public function title(): string
    {
        return $this->getMessage();
    }

    public static function badRequest(string $type, string $detail): self
    {
        return new self(400, $type, 'Bad request', $detail);
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $detail = 'The request contains invalid fields.'): self
    {
        return new self(422, 'validation_failed', 'Validation failed', $detail, $errors);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, 'not_found', 'Not found', $detail);
    }

    public static function forbidden(string $detail = 'You do not have access to this resource.'): self
    {
        return new self(403, 'forbidden', 'Forbidden', $detail);
    }

    public static function unauthorized(string $detail = 'Authentication required.', string $type = 'unauthorized'): self
    {
        return new self(401, $type, 'Unauthorized', $detail);
    }

    public static function conflict(string $type, string $detail): self
    {
        return new self(409, $type, 'Conflict', $detail);
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests', 'Rate limit exceeded.', [], ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}
