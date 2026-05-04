<?php

declare(strict_types=1);

namespace Nene2\Error;

use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (RouteNotFoundException) {
            return $this->problemDetails->create(
                $request,
                'not-found',
                'Not Found',
                404,
                'The requested resource was not found.',
            );
        } catch (MethodNotAllowedException $exception) {
            return $this->problemDetails
                ->create(
                    $request,
                    'method-not-allowed',
                    'Method Not Allowed',
                    405,
                    'The requested resource does not support this HTTP method.',
                )
                ->withHeader('Allow', implode(', ', $exception->allowedMethods()));
        } catch (Throwable) {
            return $this->problemDetails->create(
                $request,
                'internal-server-error',
                'Internal Server Error',
                500,
                'The server encountered an unexpected condition.',
            );
        }
    }
}
