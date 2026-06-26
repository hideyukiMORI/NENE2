<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Default {@see DatabaseCandidateInspector}: classifies a candidate from its migration ledger and
 * the application's own known migration versions, with no application-specific configuration.
 *
 * Knowledge of the concrete ledger table name (`phinx_log` by default, ADR 0004) lives only here —
 * the {@see DatabaseCandidateInspector} interface and the framework core stay database-agnostic.
 *
 * Read-only is enforced as a mechanism, not a convention: the candidate connection is put into a
 * read-only mode immediately after opening (SQLite `PRAGMA query_only`, MySQL/PostgreSQL
 * `START TRANSACTION READ ONLY`). A SELECT-only credential is still recommended as defence in depth.
 *
 * Part of the public API stability guarantee (ADR 0009).
 */
final readonly class DefaultDatabaseCandidateInspector implements DatabaseCandidateInspector
{
    /**
     * @param list<string> $applicationMigrationVersions The migration versions the application knows
     *        about (e.g. Phinx version ids as strings). Used to distinguish `compatible` / `ahead` /
     *        `partial`. When empty, the inspector cannot verify versions and downgrades an otherwise
     *        safe verdict to `needs_review` with the `migration_versions_unknown` reason code.
     * @param string $ledgerTable The migration ledger table name. Defaults to Phinx's `phinx_log`.
     */
    public function __construct(
        private array $applicationMigrationVersions = [],
        private string $ledgerTable = 'phinx_log',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->ledgerTable) !== 1) {
            throw new InvalidArgumentException('Ledger table name must be a bare SQL identifier.');
        }
    }

    public function inspect(CandidateProfile $profile): PreflightVerdict
    {
        try {
            $pdo = $profile->connectionFactory->create();
        } catch (Throwable) {
            return PreflightVerdict::unreachable();
        }

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            $this->enforceReadOnly($pdo, $driver);

            $tables = $this->listTables($pdo, $driver);
            $schemaRecognized = in_array($this->ledgerTable, $tables, true);
            $candidateVersions = $schemaRecognized ? $this->ledgerVersions($pdo) : [];
            $nonLedgerTables = array_values(array_filter($tables, fn (string $t): bool => $t !== $this->ledgerTable));
            $populated = $nonLedgerTables !== [] || $candidateVersions !== [];

            return $this->classify($profile, $schemaRecognized, $populated, $tables, $candidateVersions);
        } catch (Throwable) {
            return new PreflightVerdict(
                reachable: true,
                schemaRecognized: null,
                migrationState: null,
                populated: null,
                recommendation: PreflightRecommendation::Refuse,
                reasonCodes: ['inspection_failed'],
            );
        } finally {
            if ($driver === 'mysql' || $driver === 'pgsql') {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Best effort: the candidate connection is discarded immediately after.
                }
            }
        }
    }

    /**
     * @param list<string> $tables
     * @param list<string> $candidateVersions
     */
    private function classify(
        CandidateProfile $profile,
        bool $schemaRecognized,
        bool $populated,
        array $tables,
        array $candidateVersions,
    ): PreflightVerdict {
        [$state, $recommendation, $reasonCodes] = $this->classifyState(
            $schemaRecognized,
            $tables,
            $candidateVersions,
        );

        // Multi-tenant guard (#1419 / A): never emit an unconditional `safe` for a multi-tenant
        // application while tenant identity is unevaluated — a candidate that merely matches the
        // migration state could still belong to a different tenant. Downgrade to needs_review so the
        // promise "A alone is single-tenant safe only" holds at the verdict level. Resolved by #1420.
        if ($profile->multiTenant && $recommendation === PreflightRecommendation::Safe) {
            $recommendation = PreflightRecommendation::NeedsReview;
            $reasonCodes[] = 'tenant_unevaluated';
        }

        return new PreflightVerdict(
            reachable: true,
            schemaRecognized: $schemaRecognized,
            migrationState: $state,
            populated: $populated,
            recommendation: $recommendation,
            reasonCodes: $reasonCodes,
        );
    }

    /**
     * @param list<string> $tables
     * @param list<string> $candidateVersions
     * @return array{0: MigrationState, 1: PreflightRecommendation, 2: list<string>}
     */
    private function classifyState(bool $schemaRecognized, array $tables, array $candidateVersions): array
    {
        if ($tables === []) {
            return [MigrationState::Fresh, PreflightRecommendation::NeedsMigration, ['needs_migration']];
        }

        if (!$schemaRecognized) {
            return [MigrationState::Foreign, PreflightRecommendation::Refuse, ['foreign_schema']];
        }

        $nonLedgerTables = array_values(array_filter($tables, fn (string $t): bool => $t !== $this->ledgerTable));

        // Ledger present but nothing applied and no application tables — an initialized-but-empty
        // database is fresh, not partial.
        if ($candidateVersions === [] && $nonLedgerTables === []) {
            return [MigrationState::Fresh, PreflightRecommendation::NeedsMigration, ['needs_migration']];
        }

        if ($this->applicationMigrationVersions === []) {
            if ($candidateVersions === []) {
                return [MigrationState::Fresh, PreflightRecommendation::NeedsMigration, ['needs_migration']];
            }

            return [
                MigrationState::Compatible,
                PreflightRecommendation::NeedsReview,
                ['compatible', 'migration_versions_unknown'],
            ];
        }

        $extra = array_diff($candidateVersions, $this->applicationMigrationVersions);

        if ($extra !== []) {
            return [MigrationState::Ahead, PreflightRecommendation::Refuse, ['migrations_ahead']];
        }

        $missing = array_diff($this->applicationMigrationVersions, $candidateVersions);

        if ($missing !== []) {
            return [MigrationState::Partial, PreflightRecommendation::NeedsMigration, ['partial_migrations']];
        }

        return [MigrationState::Compatible, PreflightRecommendation::Safe, ['compatible']];
    }

    private function enforceReadOnly(PDO $pdo, string $driver): void
    {
        match ($driver) {
            'sqlite' => $pdo->exec('PRAGMA query_only = ON'),
            'mysql', 'pgsql' => $pdo->exec('START TRANSACTION READ ONLY'),
            // Unknown driver: the inspector issues only SELECTs, so behaviour stays read-only even
            // without an explicit guard. A SELECT-only credential remains the recommended backstop.
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function listTables(PDO $pdo, string $driver): array
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')",
            default => throw new InvalidArgumentException(sprintf('Unsupported driver "%s".', $driver)),
        };

        $statement = $pdo->query($sql);

        if ($statement === false) {
            return [];
        }

        $names = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function ledgerVersions(PDO $pdo): array
    {
        // $this->ledgerTable is validated as a bare identifier in the constructor.
        $statement = $pdo->query(sprintf('SELECT version FROM %s', $this->ledgerTable));

        if ($statement === false) {
            return [];
        }

        $versions = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (is_scalar($version)) {
                $versions[] = (string) $version;
            }
        }

        return $versions;
    }
}
