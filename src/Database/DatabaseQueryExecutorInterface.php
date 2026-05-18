<?php

declare(strict_types=1);

namespace Nene2\Database;

/**
 * Executes parameterised SQL queries against the database connection.
 *
 * Implement this interface to swap the persistence backend (e.g. SQLite for tests,
 * MySQL for production) without changing repository code.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @phpstan-type SqlParameter string|int|float|bool|null
 * @phpstan-type SqlParameters array<string|int, SqlParameter>
 * @phpstan-type SqlRow array<string, mixed>
 */
interface DatabaseQueryExecutorInterface
{
    /**
     * @param SqlParameters $parameters
     */
    public function execute(string $sql, array $parameters = []): int;

    public function lastInsertId(): int;

    /**
     * @param SqlParameters $parameters
     * @return SqlRow|null
     */
    public function fetchOne(string $sql, array $parameters = []): ?array;

    /**
     * @param SqlParameters $parameters
     * @return list<SqlRow>
     */
    public function fetchAll(string $sql, array $parameters = []): array;
}
