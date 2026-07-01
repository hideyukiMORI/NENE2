<?php

declare(strict_types=1);

namespace Nene2\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Emits a PSR-7 response to PHP's output buffer.
 *
 * Call from the application front controller after the middleware pipeline
 * returns a response. Part of the public API stability guarantee.
 */
final class ResponseEmitter
{
    /**
     * @param string|null $requestMethod The request's HTTP method. When it is `HEAD`,
     *                                    the message body is suppressed per RFC 7231 §4.3.2
     *                                    while the headers (including Content-Length) are
     *                                    still emitted. Pass `$request->getMethod()` from the
     *                                    front controller; omit for non-HEAD-aware callers.
     */
    public function emit(ResponseInterface $response, ?string $requestMethod = null): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        // RFC 7231 §4.3.2: a response to a HEAD request carries the same headers as the
        // equivalent GET but must not include a message body. The router serves HEAD via
        // the GET handler, so the body is present here and must be dropped on emit.
        if ($requestMethod !== null && strtoupper($requestMethod) === 'HEAD') {
            return;
        }

        echo (string) $response->getBody();
    }
}
