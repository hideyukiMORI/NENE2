<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\Preflight;

use Nene2\Database\DatabaseConnectionException;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\Preflight\CandidateProfile;
use Nene2\Database\Preflight\DefaultDatabaseCandidateInspector;
use Nene2\Database\Preflight\MigrationState;
use Nene2\Database\Preflight\PreflightRecommendation;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DefaultDatabaseCandidateInspectorTest extends TestCase
{
    public function testCompatibleCandidateIsSafe(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101', '20260102']);

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101', '20260102']))
            ->inspect($this->profile($pdo));

        self::assertTrue($verdict->reachable);
        self::assertTrue($verdict->schemaRecognized);
        self::assertSame(MigrationState::Compatible, $verdict->migrationState);
        self::assertTrue($verdict->populated);
        self::assertSame(PreflightRecommendation::Safe, $verdict->recommendation);
        self::assertSame(['compatible'], $verdict->reasonCodes);
        self::assertSame('not_evaluated', $verdict->appIdentity);
        self::assertSame('not_applicable', $verdict->tenant);
    }

    public function testAheadCandidateIsRefused(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101', '20260102', '20260103']);

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101', '20260102']))
            ->inspect($this->profile($pdo));

        self::assertSame(MigrationState::Ahead, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::Refuse, $verdict->recommendation);
        self::assertSame(['migrations_ahead'], $verdict->reasonCodes);
    }

    public function testPartialCandidateNeedsMigration(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101']);

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101', '20260102']))
            ->inspect($this->profile($pdo));

        self::assertSame(MigrationState::Partial, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::NeedsMigration, $verdict->recommendation);
        self::assertSame(['partial_migrations'], $verdict->reasonCodes);
    }

    public function testForeignSchemaIsRefused(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY)');

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101']))
            ->inspect($this->profile($pdo));

        self::assertFalse($verdict->schemaRecognized);
        self::assertTrue($verdict->populated);
        self::assertSame(MigrationState::Foreign, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::Refuse, $verdict->recommendation);
        self::assertSame(['foreign_schema'], $verdict->reasonCodes);
    }

    public function testFreshEmptyCandidateNeedsMigration(): void
    {
        $verdict = (new DefaultDatabaseCandidateInspector(['20260101']))
            ->inspect($this->profile($this->sqlite()));

        self::assertFalse($verdict->schemaRecognized);
        self::assertFalse($verdict->populated);
        self::assertSame(MigrationState::Fresh, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::NeedsMigration, $verdict->recommendation);
        self::assertSame(['needs_migration'], $verdict->reasonCodes);
    }

    public function testInitializedButEmptyLedgerIsFresh(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, []);

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101', '20260102']))
            ->inspect($this->profile($pdo));

        self::assertTrue($verdict->schemaRecognized);
        self::assertFalse($verdict->populated);
        self::assertSame(MigrationState::Fresh, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::NeedsMigration, $verdict->recommendation);
    }

    public function testUnknownApplicationVersionsDowngradesToNeedsReview(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101']);

        $verdict = (new DefaultDatabaseCandidateInspector([]))
            ->inspect($this->profile($pdo));

        self::assertSame(MigrationState::Compatible, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::NeedsReview, $verdict->recommendation);
        self::assertSame(['compatible', 'migration_versions_unknown'], $verdict->reasonCodes);
    }

    public function testMultiTenantSafeIsDowngradedToNeedsReview(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101']);

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101']))
            ->inspect($this->profile($pdo, multiTenant: true));

        self::assertSame(MigrationState::Compatible, $verdict->migrationState);
        self::assertSame(PreflightRecommendation::NeedsReview, $verdict->recommendation);
        self::assertSame(['compatible', 'tenant_unevaluated'], $verdict->reasonCodes);
    }

    public function testMultiTenantGuardDoesNotAffectRefuse(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY)');

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101']))
            ->inspect($this->profile($pdo, multiTenant: true));

        self::assertSame(PreflightRecommendation::Refuse, $verdict->recommendation);
        self::assertSame(['foreign_schema'], $verdict->reasonCodes);
    }

    public function testUnreachableCandidateIsRefused(): void
    {
        $factory = new class () implements DatabaseConnectionFactoryInterface {
            public function create(): PDO
            {
                throw new DatabaseConnectionException('Candidate is down.');
            }
        };

        $verdict = (new DefaultDatabaseCandidateInspector(['20260101']))
            ->inspect(new CandidateProfile('restore', $factory));

        self::assertFalse($verdict->reachable);
        self::assertNull($verdict->schemaRecognized);
        self::assertNull($verdict->migrationState);
        self::assertNull($verdict->populated);
        self::assertSame(PreflightRecommendation::Refuse, $verdict->recommendation);
        self::assertSame(['unreachable'], $verdict->reasonCodes);
    }

    public function testCandidateConnectionIsLeftReadOnly(): void
    {
        $pdo = $this->sqlite();
        $this->seedLedger($pdo, ['20260101']);

        (new DefaultDatabaseCandidateInspector(['20260101']))->inspect($this->profile($pdo));

        $statement = $pdo->query('PRAGMA query_only');
        self::assertNotFalse($statement);
        self::assertSame('1', (string) $statement->fetchColumn());

        $this->expectException(PDOException::class);
        $pdo->exec('CREATE TABLE injected (id INTEGER)');
    }

    private function sqlite(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @param list<string> $versions
     */
    private function seedLedger(PDO $pdo, array $versions): void
    {
        $pdo->exec('CREATE TABLE phinx_log (version BIGINT PRIMARY KEY, migration_name VARCHAR(100))');

        foreach ($versions as $version) {
            $statement = $pdo->prepare('INSERT INTO phinx_log (version, migration_name) VALUES (:v, :n)');
            $statement->execute([':v' => $version, ':n' => 'Migration' . $version]);
        }
    }

    private function profile(PDO $pdo, bool $multiTenant = false): CandidateProfile
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private PDO $pdo)
            {
            }

            public function create(): PDO
            {
                return $this->pdo;
            }
        };

        return new CandidateProfile('restore', $factory, $multiTenant);
    }
}
