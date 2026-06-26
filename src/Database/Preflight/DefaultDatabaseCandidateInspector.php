<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Default {@see DatabaseCandidateInspector}: classifies a candidate from its migration ledger, the
 * application's own known migration versions, and (when configured) the application identity marker.
 *
 * Knowledge of the concrete ledger table name (`phinx_log` by default, ADR 0004) and the identity
 * marker table lives only here — the {@see DatabaseCandidateInspector} interface and the framework
 * core stay database-agnostic. Tenant matching is intentionally limited to the marker comparison;
 * richer tenant resolution belongs in an application-specific inspector via the interface.
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
     * @param ApplicationIdentity|null $applicationIdentity The inspecting application's own identity.
     *        When provided, the inspector reads the candidate's identity marker and reports
     *        `app_identity` (`match` / `mismatch` / `absent`) and, for multi-tenant identities,
     *        `tenant` (`match` / `mismatch`). When null, both stay at their A-scope placeholders
     *        (`not_evaluated` / `not_applicable`) and the legacy multi-tenant guard applies.
     * @param string $identityTable The identity marker table name written by {@see ApplicationIdentityMarker}.
     */
    public function __construct(
        private array $applicationMigrationVersions = [],
        private string $ledgerTable = 'phinx_log',
        private ?ApplicationIdentity $applicationIdentity = null,
        private string $identityTable = 'nene2_app_identity',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->ledgerTable) !== 1) {
            throw new InvalidArgumentException('Ledger table name must be a bare SQL identifier.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->identityTable) !== 1) {
            throw new InvalidArgumentException('Identity table name must be a bare SQL identifier.');
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
            $appTables = $this->applicationTables($tables);
            $populated = $appTables !== [] || $candidateVersions !== [];
            $marker = $this->applicationIdentity !== null ? $this->readIdentityMarker($pdo, $tables) : null;

            return $this->classify($profile, $schemaRecognized, $populated, $appTables, $candidateVersions, $marker);
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
     * @param list<string> $appTables
     * @param list<string> $candidateVersions
     * @param array{application_id: string, tenant_id: string|null}|null $marker
     */
    private function classify(
        CandidateProfile $profile,
        bool $schemaRecognized,
        bool $populated,
        array $appTables,
        array $candidateVersions,
        ?array $marker,
    ): PreflightVerdict {
        [$state, $recommendation, $reasonCodes] = $this->classifyState(
            $schemaRecognized,
            $appTables,
            $candidateVersions,
        );

        [$appIdentity, $tenant, $recommendation, $reasonCodes] = $this->applyIdentity(
            $profile,
            $recommendation,
            $reasonCodes,
            $marker,
        );

        return new PreflightVerdict(
            reachable: true,
            schemaRecognized: $schemaRecognized,
            migrationState: $state,
            populated: $populated,
            recommendation: $recommendation,
            reasonCodes: $reasonCodes,
            appIdentity: $appIdentity,
            tenant: $tenant,
        );
    }

    /**
     * @param list<string> $appTables
     * @param list<string> $candidateVersions
     * @return array{0: MigrationState, 1: PreflightRecommendation, 2: list<string>}
     */
    private function classifyState(bool $schemaRecognized, array $appTables, array $candidateVersions): array
    {
        if (!$schemaRecognized) {
            if ($appTables === []) {
                return [MigrationState::Fresh, PreflightRecommendation::NeedsMigration, ['needs_migration']];
            }

            return [MigrationState::Foreign, PreflightRecommendation::Refuse, ['foreign_schema']];
        }

        // Ledger present but nothing applied and no application tables — an initialized-but-empty
        // database is fresh, not partial.
        if ($candidateVersions === [] && $appTables === []) {
            return [MigrationState::Fresh, PreflightRecommendation::NeedsMigration, ['needs_migration']];
        }

        if ($this->applicationMigrationVersions === []) {
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

    /**
     * Evaluate the application identity / tenant marker and adjust the recommendation.
     *
     * @param list<string> $reasonCodes
     * @param array{application_id: string, tenant_id: string|null}|null $marker
     * @return array{0: string, 1: string, 2: PreflightRecommendation, 3: list<string>}
     */
    private function applyIdentity(
        CandidateProfile $profile,
        PreflightRecommendation $recommendation,
        array $reasonCodes,
        ?array $marker,
    ): array {
        // Identity not configured: preserve A-scope behaviour, including the legacy multi-tenant guard
        // that withholds an unconditional `safe` until tenant identity can be evaluated.
        if ($this->applicationIdentity === null) {
            if ($profile->multiTenant && $recommendation === PreflightRecommendation::Safe) {
                $reasonCodes[] = 'tenant_unevaluated';

                return ['not_evaluated', 'not_applicable', PreflightRecommendation::NeedsReview, $reasonCodes];
            }

            return ['not_evaluated', 'not_applicable', $recommendation, $reasonCodes];
        }

        // Marker absent must NOT fail closed (#1420): a legitimate pre-existing database that predates
        // the identity marker would otherwise be refused. Keep the migration-based recommendation and
        // flag it as unverified instead.
        if ($marker === null) {
            $reasonCodes[] = 'identity_unverified';

            return ['absent', 'not_applicable', $recommendation, $reasonCodes];
        }

        // A marker that belongs to a different application is the one identity signal that refuses.
        if ($marker['application_id'] !== $this->applicationIdentity->applicationId) {
            $reasonCodes[] = 'identity_mismatch';

            return ['mismatch', 'not_applicable', PreflightRecommendation::Refuse, $reasonCodes];
        }

        // Application identity matches. Single-tenant applications stop here.
        if ($this->applicationIdentity->tenantId === null) {
            return ['match', 'not_applicable', $recommendation, $reasonCodes];
        }

        if (($marker['tenant_id'] ?? null) === $this->applicationIdentity->tenantId) {
            return ['match', 'match', $recommendation, $reasonCodes];
        }

        $reasonCodes[] = 'tenant_mismatch';

        return ['match', 'mismatch', PreflightRecommendation::Refuse, $reasonCodes];
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
     * Application tables are everything except the framework-internal ledger and identity marker.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private function applicationTables(array $tables): array
    {
        $internal = [$this->ledgerTable, $this->identityTable];

        return array_values(array_filter($tables, fn (string $t): bool => !in_array($t, $internal, true)));
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

    /**
     * @param list<string> $tables
     * @return array{application_id: string, tenant_id: string|null}|null
     */
    private function readIdentityMarker(PDO $pdo, array $tables): ?array
    {
        if (!in_array($this->identityTable, $tables, true)) {
            return null;
        }

        // $this->identityTable is validated as a bare identifier in the constructor.
        $statement = $pdo->query(sprintf('SELECT application_id, tenant_id FROM %s LIMIT 1', $this->identityTable));

        if ($statement === false) {
            return null;
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !isset($row['application_id']) || !is_scalar($row['application_id'])) {
            return null;
        }

        $tenant = $row['tenant_id'] ?? null;

        return [
            'application_id' => (string) $row['application_id'],
            'tenant_id' => is_scalar($tenant) ? (string) $tenant : null,
        ];
    }
}
