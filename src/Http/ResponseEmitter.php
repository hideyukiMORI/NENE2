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
    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        echo (string) $response->getBody();
    }
}
