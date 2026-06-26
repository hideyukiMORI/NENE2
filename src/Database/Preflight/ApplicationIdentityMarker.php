<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

use InvalidArgumentException;
use Nene2\Database\DatabaseConnectionException;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use PDO;
use Throwable;

/**
 * Writes the application's {@see ApplicationIdentity} marker into its own database.
 *
 * Call {@see stamp()} once at initialization / migration time, and once against any pre-existing
 * database you are adopting (the backfill path — see docs/development/machine-database-preflight.md).
 * The marker is what {@see DefaultDatabaseCandidateInspector} reads read-only to answer
 * `app_identity` and `tenant` during preflight.
 *
 * This is the only write path in the preflight feature; it runs against the application's own
 * database, never against a candidate. Idempotent: stamping replaces the single marker row.
 *
 * Part of the public API stability guarantee (ADR 0009).
 */
final readonly class ApplicationIdentityMarker
{
    public function __construct(
        private DatabaseConnectionFactoryInterface $connectionFactory,
        private string $table = 'nene2_app_identity',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table) !== 1) {
            throw new InvalidArgumentException('Identity table name must be a bare SQL identifier.');
        }
    }

    /**
     * Create the marker table if needed and stamp $identity as the single marker row, replacing any
     * existing one. Wraps the write in a transaction so a partial stamp cannot be observed.
     */
    public function stamp(ApplicationIdentity $identity): void
    {
        $pdo = $this->connectionFactory->create();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->ensureTable($pdo, $driver);

        $pdo->beginTransaction();

        try {
            $pdo->exec(sprintf('DELETE FROM %s', $this->table));

            $statement = $pdo->prepare(
                sprintf('INSERT INTO %s (application_id, tenant_id) VALUES (:application_id, :tenant_id)', $this->table),
            );
            $statement->execute([
                ':application_id' => $identity->applicationId,
                ':tenant_id' => $identity->tenantId,
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new DatabaseConnectionException('Failed to stamp application identity marker.', previous: $exception);
        }
    }

    private function ensureTable(PDO $pdo, string $driver): void
    {
        $sql = match ($driver) {
            'sqlite' => sprintf(
                'CREATE TABLE IF NOT EXISTS %s (application_id TEXT NOT NULL, tenant_id TEXT NULL)',
                $this->table,
            ),
            'mysql' => sprintf(
                'CREATE TABLE IF NOT EXISTS %s (application_id VARCHAR(190) NOT NULL, tenant_id VARCHAR(190) NULL)',
                $this->table,
            ),
            'pgsql' => sprintf(
                'CREATE TABLE IF NOT EXISTS %s (application_id VARCHAR(190) NOT NULL, tenant_id VARCHAR(190))',
                $this->table,
            ),
            default => throw new InvalidArgumentException(sprintf('Unsupported driver "%s".', $driver)),
        };

        $pdo->exec($sql);
    }
}
