<?php

declare(strict_types=1);

namespace Analytics\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Interfaces\ErrorHandlerInterface;

final readonly class ProblemDetailsErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private JsonResponder $responder,
        private LoggerInterface $logger,
        private bool $exposeDetails,
    ) {}

    public function __invoke(ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails): ResponseInterface
    {
        $requestId = $request->getAttribute(RequestAttributes::REQUEST_ID);
        $requestId = \is_string($requestId) ? $requestId : null;

        if ($exception instanceof ApiProblem) {
            $problem = $exception;
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $problem = new ApiProblem(405, 'method_not_allowed', 'Method not allowed', $exception->getMessage(), [], [], ['Allow' => implode(', ', $exception->getAllowedMethods())]);
        } elseif ($exception instanceof HttpException) {
            $code = $exception->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;
            $problem = new ApiProblem($status, match ($status) {
                404 => 'not_found',
                400 => 'bad_request',
                default => 'http_error',
            }, $exception->getTitle() !== '' ? $exception->getTitle() : 'Error', $exception->getMessage());
        } else {
            $this->logger->error('Unhandled exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'request_id' => $requestId,
                'path' => $request->getUri()->getPath(),
            ]);
            $problem = new ApiProblem(500, 'internal_error', 'Internal server error', $this->exposeDetails ? $exception::class . ': ' . $exception->getMessage() : 'An unexpected error occurred.');
        }

        return $this->responder->problem($problem, $requestId);
    }
}
