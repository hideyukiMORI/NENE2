<?php

declare(strict_types=1);

namespace Nene2\Error;

use Nene2\Http\JsonBodyParseException;
use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    /**
     * @param list<DomainExceptionHandlerInterface> $domainHandlers
     * @param bool $debug When true, the exception message is included in the 500 response `detail`
     *                    field. Never set to true in production.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private array $domainHandlers = [],
        private bool $debug = false,
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
        } catch (JsonBodyParseException $exception) {
            return $this->problemDetails->create(
                $request,
                'invalid-json',
                'Invalid JSON',
                400,
                $exception->getMessage(),
            );
        } catch (ValidationException $exception) {
            return $this->problemDetails->create(
                $request,
                'validation-failed',
                'Validation Failed',
                422,
                'The request contains invalid values.',
                [
                    'errors' => $exception->errorsForResponse(),
                ],
            );
        } catch (Throwable $exception) {
            foreach ($this->domainHandlers as $domainHandler) {
                if ($domainHandler->supports($exception)) {
                    return $domainHandler->handle($exception, $request);
                }
            }

            return $this->problemDetails->create(
                $request,
                'internal-server-error',
                'Internal Server Error',
                500,
                $this->debug
                    ? $exception->getMessage()
                    : 'The server encountered an unexpected condition.',
            );
        }
    }
}
