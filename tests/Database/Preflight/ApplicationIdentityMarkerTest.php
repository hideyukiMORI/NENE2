<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\Preflight;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\Preflight\ApplicationIdentity;
use Nene2\Database\Preflight\ApplicationIdentityMarker;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApplicationIdentityMarkerTest extends TestCase
{
    public function testStampCreatesMarkerTableAndRow(): void
    {
        $pdo = $this->sqlite();

        (new ApplicationIdentityMarker($this->factory($pdo)))->stamp(new ApplicationIdentity('app-A'));

        $row = $this->fetchRow($pdo, 'SELECT application_id, tenant_id FROM nene2_app_identity');

        self::assertIsArray($row);
        self::assertSame('app-A', $row['application_id']);
        self::assertNull($row['tenant_id']);
    }

    public function testStampStoresTenant(): void
    {
        $pdo = $this->sqlite();

        (new ApplicationIdentityMarker($this->factory($pdo)))->stamp(new ApplicationIdentity('app-A', 'tenant-1'));

        $row = $this->fetchRow($pdo, 'SELECT application_id, tenant_id FROM nene2_app_identity');

        self::assertIsArray($row);
        self::assertSame('tenant-1', $row['tenant_id']);
    }

    public function testStampIsIdempotentAndKeepsASingleRow(): void
    {
        $pdo = $this->sqlite();
        $marker = new ApplicationIdentityMarker($this->factory($pdo));

        $marker->stamp(new ApplicationIdentity('app-A'));
        $marker->stamp(new ApplicationIdentity('app-A', 'tenant-9'));

        $statement = $pdo->query('SELECT COUNT(*) FROM nene2_app_identity');
        self::assertNotFalse($statement);
        $count = $statement->fetchColumn();
        $row = $this->fetchRow($pdo, 'SELECT application_id, tenant_id FROM nene2_app_identity');

        self::assertSame('1', (string) $count);
        self::assertIsArray($row);
        self::assertSame('tenant-9', $row['tenant_id']);
    }

    private function fetchRow(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function sqlite(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function factory(PDO $pdo): DatabaseConnectionFactoryInterface
    {
        return new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private PDO $pdo)
            {
            }

            public function create(): PDO
            {
                return $this->pdo;
            }
        };
    }
}
