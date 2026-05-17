<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Auth\BearerTokenMiddleware;
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
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class RuntimeApplicationFactory
{
    /**
     * @param list<DomainExceptionHandlerInterface> $domainExceptionHandlers
     * @param list<callable(Router): void> $routeRegistrars
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?LoggerInterface $logger = null,
        private ?string $machineApiKey = null,
        private array $domainExceptionHandlers = [],
        private ?RequestIdHolder $requestIdHolder = null,
        private array $routeRegistrars = [],
        private ?BearerTokenMiddleware $bearerTokenMiddleware = null,
    ) {
    }

    public function create(): RequestHandlerInterface
    {
        $logger = $this->logger ?? new NullLogger();

        $logger->info('NENE2 runtime started.', [
            'machine_api_key' => $this->machineApiKey !== null,
            'bearer_middleware' => $this->bearerTokenMiddleware !== null,
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
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'status' => 'ok',
                    'service' => $framework->name(),
                ]),
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

        if ($this->bearerTokenMiddleware !== null) {
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
            new CorsMiddleware($this->responseFactory),
            new ErrorHandlerMiddleware($problemDetails, $this->domainExceptionHandlers),
            new RequestSizeLimitMiddleware($problemDetails),
            new ApiKeyAuthenticationMiddleware($problemDetails, $this->machineApiKey, ['/machine/health']),
        ];

        if ($this->bearerTokenMiddleware !== null) {
            $middlewareStack[] = $this->bearerTokenMiddleware;
        }

        return new MiddlewareDispatcher($middlewareStack, $router);
    }
}
