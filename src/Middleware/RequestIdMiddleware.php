<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'nene2.request_id';

    public function __construct(
        private string $headerName = 'X-Request-Id',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->safeRequestId($request->getHeaderLine($this->headerName)) ?? $this->generateRequestId();
        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $requestId));

        return $response->withHeader($this->headerName, $requestId);
    }

    private function safeRequestId(string $requestId): ?string
    {
        if ($requestId === '' || strlen($requestId) > 128) {
            return null;
        }

        if (preg_match('/\A[A-Za-z0-9._:-]+\z/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
