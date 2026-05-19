<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\FrameworkInfo;
use Nene2\Log\RequestIdHolder;
use Nene2\Middleware\ApiKeyAuthenticationMiddleware;
use Nene2\Middleware\CorsMiddleware;
use Nene2\Middleware\MiddlewareDispatcher;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Middleware\RequestLoggingMiddleware;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nene2\Middleware\SecurityHeadersMiddleware;
use Nene2\Middleware\ThrottleMiddleware;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Assembles the full PSR-15 middleware pipeline and {@see Router} for a NENE2 application.
 *
 * Construct this once at the application entry point, pass your route registrars, health
 * checks, and optional middleware, then call {@see create()} to obtain the request handler
 * that your front controller dispatches to.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class RuntimeApplicationFactory
{
    /**
     * @param list<DomainExceptionHandlerInterface> $domainExceptionHandlers
     * @param list<callable(Router): void> $routeRegistrars
     * @param MiddlewareInterface|list<MiddlewareInterface>|null $authMiddleware Authentication middleware injected
     *                                              into the pipeline after the request size limit. Accepts a single
     *                                              {@see MiddlewareInterface} or a list of middlewares that are
     *                                              pushed in order (first item runs first). Use a list to stack
     *                                              multiple middlewares — e.g. a tenant extractor followed by a JWT
     *                                              verifier — without wrapping them in a composite.
     * @param list<HealthCheckInterface> $healthChecks
     * @param list<string> $allowedOrigins CORS-allowed origins (e.g. `['https://app.example.com']`).
     *                                     An empty list (the default) silently disables all CORS headers.
     *                                     Always set this explicitly in production.
     * @param list<string> $machineApiKeyExcludedPaths Paths that bypass the machine API key check even when
     *                                                  `$machineApiKey` is set. Useful when a route should remain
     *                                                  public while all other routes require an API key.
     *                                                  Only has effect when `$machineApiKey` is non-null and
     *                                                  `$machineApiKeyProtectedPaths` is empty.
     * @param list<string> $machineApiKeyProtectedPaths Exact paths protected by the machine API key. Combined with
     *                                                   `$machineApiKeyProtectedPathPrefixes` in union mode — a path
     *                                                   is protected when it matches any exact entry OR any prefix.
     *                                                   Defaults to `['/machine/health']`.
     * @param list<string> $machineApiKeyProtectedPathPrefixes Path prefixes protected by the machine API key
     *                                                          (e.g. `['/admin/']` matches `/admin/users/42`).
     *                                                          Combined with `$machineApiKeyProtectedPaths` in union
     *                                                          mode.
     * @param list<string> $machineApiKeyProtectedMethods HTTP methods that require the machine API key
     *                                                     (uppercase, e.g. `['POST', 'PUT', 'DELETE']`).
     *                                                     When non-empty, GET/HEAD requests pass through
     *                                                     without a key even on protected paths. Useful for
     *                                                     "public read / key-gated write" patterns.
     * @param bool $debug When true, unhandled exception messages are exposed in 500 `detail`.
     *                    Never set to true in production.
     * @param int $requestMaxBodyBytes Maximum allowed request body size in bytes. Requests exceeding
     *                                 this limit are rejected with a 413 Problem Details response.
     *                                 Defaults to 1 MiB (1 048 576 bytes). Increase for bulk-import
     *                                 or large-payload endpoints.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?LoggerInterface $logger = null,
        private ?string $machineApiKey = null,
        private array $domainExceptionHandlers = [],
        private ?RequestIdHolder $requestIdHolder = null,
        private array $routeRegistrars = [],
        private MiddlewareInterface|array|null $authMiddleware = null,
        private array $healthChecks = [],
        private ?ThrottleMiddleware $throttleMiddleware = null,
        private bool $debug = false,
        private array $allowedOrigins = [],
        private array $machineApiKeyExcludedPaths = [],
        private array $machineApiKeyProtectedPaths = ['/machine/health'],
        private array $machineApiKeyProtectedPathPrefixes = [],
        private array $machineApiKeyProtectedMethods = [],
        private int $requestMaxBodyBytes = 1_048_576,
    ) {
    }

    public function create(): RequestHandlerInterface
    {
        $logger = $this->logger ?? new NullLogger();

        /** @var list<MiddlewareInterface> $authMiddlewares */
        $authMiddlewares = match (true) {
            $this->authMiddleware === null  => [],
            is_array($this->authMiddleware) => $this->authMiddleware,
            default                         => [$this->authMiddleware],
        };

        $logger->info('NENE2 runtime started.', [
            'machine_api_key' => $this->machineApiKey !== null,
            'auth_middleware' => $authMiddlewares !== [],
        ]);

        $jsonResponses = new JsonResponseFactory($this->responseFactory, $this->streamFactory);
        $problemDetails = new ProblemDetailsResponseFactory($this->responseFactory, $this->streamFactory);
        $framework = new FrameworkInfo();

        $router = (new Router())
            ->get(
                '/',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'name' => $framework->name(),
                    'description' => $framework->description(),
                    'status' => 'ok',
                ]),
            )
            ->get(
                '/health',
                function (ServerRequestInterface $request) use ($jsonResponses, $framework) {
                    if ($this->healthChecks === []) {
                        return $jsonResponses->create([
                            'status' => 'ok',
                            'service' => $framework->name(),
                        ]);
                    }

                    $checks = [];
                    $degraded = false;

                    foreach ($this->healthChecks as $check) {
                        try {
                            $status = $check->check();
                        } catch (Throwable) {
                            $status = HealthStatus::Error;
                        }

                        $checks[$check->name()] = $status->value;

                        if ($status === HealthStatus::Error) {
                            $degraded = true;
                        }
                    }

                    return $jsonResponses->create(
                        [
                            'status' => $degraded ? 'degraded' : 'ok',
                            'service' => $framework->name(),
                            'checks' => $checks,
                        ],
                        $degraded ? 503 : 200,
                    );
                },
            )
            ->get(
                '/machine/health',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'status' => 'ok',
                    'service' => $framework->name(),
                    'credential_type' => $request->getAttribute('nene2.auth.credential_type'),
                ]),
            )
            ->get(
                '/examples/ping',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'message' => 'pong',
                    'status' => 'ok',
                ]),
            )
        ;

        if ($authMiddlewares !== []) {
            $router->get(
                '/examples/protected',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'message' => 'Welcome, authenticated user.',
                    'claims' => $request->getAttribute('nene2.auth.claims') ?? [],
                ]),
            );
        }

        foreach ($this->routeRegistrars as $registrar) {
            $registrar($router);
        }

        $middlewareStack = [
            new RequestIdMiddleware('X-Request-Id', $this->requestIdHolder),
            new RequestLoggingMiddleware($logger),
            new SecurityHeadersMiddleware(),
            new CorsMiddleware($this->responseFactory, $this->allowedOrigins),
            new ErrorHandlerMiddleware($problemDetails, $this->domainExceptionHandlers, $this->debug, $logger),
            new RequestSizeLimitMiddleware($problemDetails, $this->requestMaxBodyBytes),
            new ApiKeyAuthenticationMiddleware(
                $problemDetails,
                $this->machineApiKey,
                $this->machineApiKeyProtectedPaths,
                excludedPaths:          $this->machineApiKeyExcludedPaths,
                protectedPathPrefixes:  $this->machineApiKeyProtectedPathPrefixes,
                protectedMethods:       $this->machineApiKeyProtectedMethods,
            ),
        ];

        foreach ($authMiddlewares as $authMiddleware) {
            $middlewareStack[] = $authMiddleware;
        }

        if ($this->throttleMiddleware !== null) {
            $middlewareStack[] = $this->throttleMiddleware;
        }

        return new MiddlewareDispatcher($middlewareStack, $router);
    }
}
