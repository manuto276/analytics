<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Pipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    private function __construct(private array $middleware, private readonly RequestHandlerInterface $final) {}

    /** @param list<MiddlewareInterface> $middleware */
    public static function run(array $middleware, ServerRequestInterface $request, RequestHandlerInterface $final): ResponseInterface
    {
        return new self($middleware, $final)->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $next = array_shift($this->middleware);
        if ($next === null) {
            return $this->final->handle($request);
        }

        return $next->process($request, new self($this->middleware, $this->final));
    }
}
