<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ApiKeyAuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedPaths
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private ?string $expectedApiKey,
        private array $protectedPaths,
        private string $headerName = 'X-NENE2-API-Key',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->requiresAuthentication($request)) {
            return $handler->handle($request);
        }

        if ($this->expectedApiKey === null) {
            return $this->unauthorized($request);
        }

        $providedApiKey = $request->getHeaderLine($this->headerName);

        if ($providedApiKey === '' || !hash_equals($this->expectedApiKey, $providedApiKey)) {
            return $this->unauthorized($request);
        }

        return $handler->handle($request->withAttribute('nene2.auth.credential_type', 'api_key'));
    }

    private function requiresAuthentication(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return false;
        }

        $path = $request->getUri()->getPath() ?: '/';

        return in_array($path, $this->protectedPaths, true);
    }

    private function unauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails
            ->create(
                $request,
                'unauthorized',
                'Unauthorized',
                401,
                'A valid API key is required for this endpoint.',
            )
            ->withHeader('WWW-Authenticate', 'ApiKey realm="NENE2", header="' . $this->headerName . '"');
    }
}
