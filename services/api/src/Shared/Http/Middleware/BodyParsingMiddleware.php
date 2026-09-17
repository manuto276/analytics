<?php

declare(strict_types=1);

namespace Analytics\Shared\Http\Middleware;

use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\RequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads at most MAX_BYTES of the body and decodes JSON bodies (application/json everywhere,
 * text/plain on the collect endpoint). CSV and multipart uploads keep the raw body.
 */
final class BodyParsingMiddleware implements MiddlewareInterface
{
    public const int MAX_BYTES = 65536;
    public const int MAX_UPLOAD_BYTES = 2_097_152;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true) && $request->getBody()->getSize() === 0) {
            return $handler->handle($request);
        }

        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $path = $request->getUri()->getPath();
        $isCsv = $contentType === 'text/csv';
        $limit = $isCsv ? self::MAX_UPLOAD_BYTES : self::MAX_BYTES;

        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $limit) {
            throw new ApiProblem(413, 'payload_too_large', 'Payload too large', \sprintf('Request bodies are limited to %d bytes.', $limit));
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (!$stream->eof() && \strlen($raw) <= $limit) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (\strlen($raw) > $limit) {
            throw new ApiProblem(413, 'payload_too_large', 'Payload too large', \sprintf('Request bodies are limited to %d bytes.', $limit));
        }
        $request = $request->withAttribute(RequestAttributes::RAW_BODY, $raw);

        $jsonAllowed = $contentType === 'application/json'
            || str_ends_with($contentType, '+json')
            || ($contentType === 'text/plain' && str_starts_with($path, '/t/'))
            || ($contentType === '' && str_starts_with($path, '/t/'));

        if ($jsonAllowed && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw ApiProblem::badRequest('invalid_json', 'The request body is not valid JSON.');
            }
            if (!\is_array($decoded)) {
                throw ApiProblem::badRequest('invalid_json', 'The request body must be a JSON object or array.');
            }
            $request = $request->withParsedBody($decoded);
        }

        return $handler->handle($request);
    }
}
