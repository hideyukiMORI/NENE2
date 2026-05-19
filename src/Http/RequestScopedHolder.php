<?php

declare(strict_types=1);

namespace Nene2\Http;

use LogicException;

/**
 * A type-safe mutable holder for a single request-scoped value.
 *
 * Inject one shared instance into both the middleware that writes (e.g. a tenant extractor)
 * and the route handler / repository that reads. Because PHP-FPM and CLI follow the
 * shared-nothing model (one process per request), the holder value is never shared across
 * requests. See {@see https://nene2.dev/howto/request-scoped-state} for the full pattern
 * and the async-runtime caveat.
 *
 * Usage:
 * ```php
 * $tenantId = new RequestScopedHolder();
 * // In middleware:    $tenantId->set($extractedId);
 * // In route handler: $id = $tenantId->get();
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @template T
 */
final class RequestScopedHolder
{
    /** @var T|null */
    private mixed $value = null;

    /**
     * @param T $value
     */
    public function set(mixed $value): void
    {
        $this->value = $value;
    }

    /**
     * Returns the held value.
     *
     * @return T
     * @throws LogicException when called before {@see set()}.
     */
    public function get(): mixed
    {
        if ($this->value === null) {
            throw new LogicException(
                static::class . '::get() called before set() — make sure the middleware that populates this holder runs before the route handler.',
            );
        }

        return $this->value;
    }

    public function isSet(): bool
    {
        return $this->value !== null;
    }

    /**
     * Clears the held value. Useful in long-running processes (Swoole, ReactPHP)
     * to reset state between requests.
     */
    public function reset(): void
    {
        $this->value = null;
    }
}
