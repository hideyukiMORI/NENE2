<?php

declare(strict_types=1);

namespace Nene2\Testing;

use InvalidArgumentException;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;

/**
 * Sanctioned wiring helper for database-backed tests.
 *
 * The shipped PDO adapters (`PdoConnectionFactory`, `PdoDatabaseQueryExecutor`,
 * `PdoDatabaseTransactionManager`) are marked `@internal` by ADR 0009. Tests that
 * need to drive them directly should construct this kit instead of reaching into
 * those classes.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0012).
 */
final readonly class DatabaseTestKit
{
    public function __construct(
        public DatabaseConnectionFactoryInterface $connectionFactory,
        public DatabaseQueryExecutorInterface $queryExecutor,
        public DatabaseTransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * Build a kit backed by a file-based SQLite database.
     *
     * `:memory:` is intentionally rejected: `transactional()` opens a separate
     * connection via the connection factory, which for `:memory:` would observe
     * an empty database. Pass a file path under {@see sys_get_temp_dir()} for
     * isolation, e.g. `sys_get_temp_dir() . '/' . uniqid('kit-', true) . '.sqlite'`.
     */
    public static function sqlite(string $path): self
    {
        if ($path === ':memory:') {
            throw new InvalidArgumentException(
                'DatabaseTestKit::sqlite() does not support ":memory:". '
                . 'transactional() opens a separate connection that would see an empty in-memory database. '
                . 'Use a file path (e.g. sys_get_temp_dir() . "/" . uniqid("kit-", true) . ".sqlite") instead.',
            );
        }

        return self::fromConfig(DatabaseConfig::sqlite($path));
    }

    /**
     * Build a kit from an arbitrary {@see DatabaseConfig}. Use this for MySQL or
     * PostgreSQL fixtures.
     */
    public static function fromConfig(DatabaseConfig $config): self
    {
        $factory = new PdoConnectionFactory($config);

        return new self(
            connectionFactory: $factory,
            queryExecutor: new PdoDatabaseQueryExecutor($factory),
            transactionManager: new PdoDatabaseTransactionManager($factory),
        );
    }
}
