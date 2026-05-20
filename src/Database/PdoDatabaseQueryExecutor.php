<?php

declare(strict_types=1);

namespace Nene2\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * @internal
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

    public function insert(string $sql, array $parameters = []): int
    {
        $this->statement($sql, $parameters);

        return $this->lastInsertId();
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
            if ($this->isConstraintViolation($exception)) {
                throw new DatabaseConstraintException('Database constraint violated.', previous: $exception);
            }
            throw new DatabaseConnectionException('Database query could not be executed.', previous: $exception);
        }
    }

    private function isConstraintViolation(PDOException $e): bool
    {
        // SQLSTATE 23xxx = Integrity Constraint Violation (UNIQUE, FK, NOT NULL, CHECK)
        $code = (string) $e->getCode();
        return str_starts_with($code, '23')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')
            || str_contains($e->getMessage(), 'NOT NULL constraint failed')
            || str_contains($e->getMessage(), 'CHECK constraint failed');
    }

    private function connection(): PDO
    {
        return $this->connection ??= $this->connectionFactory->create();
    }
}
