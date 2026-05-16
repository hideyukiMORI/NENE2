<?php

declare(strict_types=1);

namespace Nene2\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * @phpstan-import-type SqlParameters from DatabaseQueryExecutorInterface
 * @phpstan-import-type SqlRow from DatabaseQueryExecutorInterface
 */
final class PdoDatabaseQueryExecutor implements DatabaseQueryExecutorInterface
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly DatabaseConnectionFactoryInterface $connectionFactory,
        ?PDO $connection = null,
    ) {
        $this->connection = $connection;
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->statement($sql, $parameters)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection()->lastInsertId();
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $row = $this->statement($sql, $parameters)->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        /** @var SqlRow $row */
        return $row;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        /** @var list<SqlRow> $rows */
        $rows = $this->statement($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param SqlParameters $parameters
     */
    private function statement(string $sql, array $parameters): PDOStatement
    {
        try {
            $statement = $this->connection()->prepare($sql);

            if ($statement === false) {
                throw new DatabaseConnectionException('Database statement could not be prepared.');
            }

            $statement->execute($parameters);

            return $statement;
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException('Database query could not be executed.', previous: $exception);
        }
    }

    private function connection(): PDO
    {
        return $this->connection ??= $this->connectionFactory->create();
    }
}
