<?php

declare(strict_types=1);

namespace Nene2\Routing;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @phpstan-type Route array{method: non-empty-string, path: non-empty-string, handler: callable(ServerRequestInterface): ResponseInterface}
 */
final class Router implements RequestHandlerInterface
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath() ?: '/';
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if ($route['path'] !== $path) {
                continue;
            }

            if ($route['method'] === $method) {
                return $route['handler']($request);
            }

            $allowedMethods[] = $route['method'];
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException($allowedMethods);
        }

        throw new RouteNotFoundException('No route matched the request.');
    }

    /**
     * @param non-empty-string $method
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    private function add(string $method, string $path, callable $handler): self
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Route path must start with /.');
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];

        return $this;
    }
}
