<?php

declare(strict_types=1);

namespace Nene2\Error;

use Nene2\Http\JsonBodyParseException;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    /**
     * @param list<DomainExceptionHandlerInterface> $domainHandlers
     * @param bool $debug When true, the exception message is included in the 500 response `detail`
     *                    field. Never set to true in production.
     * @param LoggerInterface $logger Unhandled exceptions are logged at ERROR level regardless of $debug.
     *                                Pass a NullLogger (default) to suppress.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private array $domainHandlers = [],
        private bool $debug = false,
        private LoggerInterface $logger = new NullLogger(),
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

            $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

            // Never log the raw exception message or the full exception object at
            // ERROR level: driver exceptions (e.g. PDOException) embed SQL text,
            // table/column names, and DSN/host fragments, which the logging policy
            // forbids. The message stays static and only class + location are kept.
            // Full detail (message + trace) is attached only in debug for local
            // diagnosis; production should route full context to an error-tracking
            // adapter, not the default logger. The request id comes from the
            // sanitized RequestIdMiddleware attribute, never the raw client header.
            $context = [
                'exception_class' => $exception::class,
                'exception_at' => $exception->getFile() . ':' . $exception->getLine(),
                'request_id' => is_string($requestId) ? $requestId : '',
            ];

            if ($this->debug) {
                $context['exception'] = $exception;
            }

            $this->logger->error('Unhandled exception while processing request.', $context);

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
