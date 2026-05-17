<?php

declare(strict_types=1);

namespace Nene2\Auth;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private TokenVerifierInterface $verifier,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '') {
            return $this->unauthorized($request, 'missing_token', 'No Bearer token was provided.');
        }

        if (!str_starts_with($authorization, 'Bearer ')) {
            return $this->unauthorized($request, 'invalid_token', 'Authorization header must use the Bearer scheme.');
        }

        $token = substr($authorization, 7);

        try {
            $claims = $this->verifier->verify($token);
        } catch (TokenVerificationException $e) {
            return $this->unauthorized($request, 'invalid_token', $e->getMessage());
        }

        return $handler->handle(
            $request
                ->withAttribute('nene2.auth.credential_type', 'bearer')
                ->withAttribute('nene2.auth.claims', $claims),
        );
    }

    private function unauthorized(ServerRequestInterface $request, string $error, string $description): ResponseInterface
    {
        return $this->problemDetails
            ->create($request, 'unauthorized', 'Unauthorized', 401, $description)
            ->withHeader(
                'WWW-Authenticate',
                sprintf('Bearer realm="NENE2", error="%s", error_description="%s"', $error, $description),
            );
    }
}
