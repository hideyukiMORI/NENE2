<?php

declare(strict_types=1);

namespace Nene2\Database;

interface DatabaseTransactionManagerInterface
{
    /**
     * @template T
     * @param callable(DatabaseQueryExecutorInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
