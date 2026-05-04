<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $handler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = array_reduce(
            array_reverse($this->middleware),
            static fn (RequestHandlerInterface $next, MiddlewareInterface $middleware): RequestHandlerInterface => new class ($middleware, $next) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            },
            $this->handler,
        );

        return $handler->handle($request);
    }
}
