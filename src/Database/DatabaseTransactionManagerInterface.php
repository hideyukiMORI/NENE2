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
     * @template T
     * @param callable(DatabaseQueryExecutorInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
