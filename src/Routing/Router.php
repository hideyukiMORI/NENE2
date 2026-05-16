<?php

declare(strict_types=1);

namespace Nene2\Routing;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @phpstan-type Route array{method: non-empty-string, path: non-empty-string, pattern: string, parameterNames: list<non-empty-string>, handler: callable(ServerRequestInterface): ResponseInterface}
 */
final class Router implements RequestHandlerInterface
{
    public const PARAMETERS_ATTRIBUTE = 'nene2.route.parameters';

    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function put(string $path, callable $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function delete(string $path, callable $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath() ?: '/';
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $parameters = $this->match($route, $path);

            if ($parameters === null) {
                continue;
            }

            if ($route['method'] === $method) {
                return $route['handler'](
                    $request->withAttribute(self::PARAMETERS_ATTRIBUTE, $parameters)
                );
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

        [$pattern, $parameterNames] = $this->compilePath($path);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'parameterNames' => $parameterNames,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * @return array{0: string, 1: list<non-empty-string>}
     */
    private function compilePath(string $path): array
    {
        $parameterNames = [];
        $segments = explode('/', $path);
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameterNames[] = $matches[1];
                $patternSegments[] = '([^/]+)';
                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException('Route path parameters must occupy a full path segment.');
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        return ['#^' . implode('/', $patternSegments) . '$#', $parameterNames];
    }

    /**
     * @param Route $route
     * @return array<string, string>|null
     */
    private function match(array $route, string $path): ?array
    {
        if (preg_match($route['pattern'], $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($route['parameterNames'] as $index => $name) {
            $parameters[$name] = rawurldecode($matches[$index + 1]);
        }

        return $parameters;
    }
}
