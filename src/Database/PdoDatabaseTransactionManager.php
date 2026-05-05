<?php

declare(strict_types=1);

namespace Nene2\Database;

use PDOException;
use Throwable;

final readonly class PdoDatabaseTransactionManager implements DatabaseTransactionManagerInterface
{
    public function __construct(
        private DatabaseConnectionFactoryInterface $connectionFactory,
    ) {
    }

    public function transactional(callable $callback): mixed
    {
        try {
            $connection = $this->connectionFactory->create();
            $connection->beginTransaction();

            try {
                $result = $callback(new PdoDatabaseQueryExecutor($this->connectionFactory, $connection));
                $connection->commit();

                return $result;
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                throw $exception;
            }
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException('Database transaction could not be completed.', previous: $exception);
        }
    }
}
