<?php

declare(strict_types=1);

namespace Nene2\Database;

/**
 * Wraps a unit of work in a database transaction.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DatabaseTransactionManagerInterface
{
    /**
     * Executes the callback inside a database transaction and returns its result.
     *
     * The callback receives a `DatabaseQueryExecutorInterface` bound to the transaction's
     * connection. **Instantiate all repositories inside the callback using this executor.**
     * Repositories injected at construction time use a different connection and execute
     * outside the transaction — rollbacks will not undo their changes.
     *
     * @template T
     * @param callable(DatabaseQueryExecutorInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
