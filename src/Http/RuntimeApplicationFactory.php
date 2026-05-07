<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\FrameworkInfo;
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
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?LoggerInterface $logger = null,
        private ?string $machineApiKey = null,
    ) {
    }

    public function create(): RequestHandlerInterface
    {
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
            );

        return new MiddlewareDispatcher(
            [
                new RequestIdMiddleware(),
                new RequestLoggingMiddleware($this->logger ?? new NullLogger()),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware($this->responseFactory),
                new ErrorHandlerMiddleware($problemDetails),
                new RequestSizeLimitMiddleware($problemDetails),
                new ApiKeyAuthenticationMiddleware($problemDetails, $this->machineApiKey, ['/machine/health']),
            ],
            $router,
        );
    }
}
