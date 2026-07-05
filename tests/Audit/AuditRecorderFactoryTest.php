<?php

declare(strict_types=1);

namespace Nene2\Tests\Audit;

use DateTimeImmutable;
use Nene2\Audit\AuditEvent;
use Nene2\Audit\AuditQuery;
use Nene2\Audit\AuditRecorderFactory;
use Nene2\Audit\AuditTableConfig;
use Nene2\Audit\PdoAuditEventRepository;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Http\ClockInterface;
use Nene2\Testing\DatabaseTestKit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditRecorderFactoryTest extends TestCase
{
    private string $path = '';

    private DatabaseTestKit $kit;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/' . uniqid('audit-tx-', true) . '.sqlite';
        $this->kit = DatabaseTestKit::sqlite($this->path);

        $this->kit->queryExecutor->execute(
            'CREATE TABLE audit_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NULL,
                actor_id VARCHAR(64) NULL,
                organization_id VARCHAR(64) NULL,
                before_json TEXT NULL,
                after_json TEXT NULL,
                metadata_json TEXT NULL,
                occurred_at DATETIME NOT NULL
            )',
        );
        $this->kit->queryExecutor->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testAuditRowCommitsAtomicallyWithMutation(): void
    {
        $factory = new AuditRecorderFactory($this->fixedClock(), AuditTableConfig::canonical());

        $this->kit->transactionManager->transactional(function (DatabaseQueryExecutorInterface $exec) use ($factory): void {
            $exec->insert('INSERT INTO widgets (name) VALUES (?)', ['gadget']);
            $factory->forExecutor($exec)->record(new AuditEvent(
                action: 'widget.created',
                entityType: 'widget',
                entityId: 1,
                organizationId: 1,
            ));
        });

        self::assertSame(1, $this->countWidgets());
        self::assertSame(1, $this->auditRepo()->count(new AuditQuery(organizationId: 1)));
    }

    public function testAuditRowRollsBackWithMutation(): void
    {
        $factory = new AuditRecorderFactory($this->fixedClock(), AuditTableConfig::canonical());

        try {
            $this->kit->transactionManager->transactional(function (DatabaseQueryExecutorInterface $exec) use ($factory): void {
                $exec->insert('INSERT INTO widgets (name) VALUES (?)', ['doomed']);
                $factory->forExecutor($exec)->record(new AuditEvent(
                    action: 'widget.created',
                    entityType: 'widget',
                    entityId: 1,
                    organizationId: 1,
                ));

                throw new RuntimeException('business rule violated after the audit write');
            });
        } catch (RuntimeException $expected) {
            self::assertSame('business rule violated after the audit write', $expected->getMessage());
        }

        // Neither the business row nor its audit row survive — they committed as one unit.
        self::assertSame(0, $this->countWidgets());
        self::assertSame(0, $this->auditRepo()->count(new AuditQuery(organizationId: 1)));
    }

    private function countWidgets(): int
    {
        $row = $this->kit->queryExecutor->fetchOne('SELECT COUNT(*) AS cnt FROM widgets');

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    private function auditRepo(): PdoAuditEventRepository
    {
        return new PdoAuditEventRepository($this->kit->queryExecutor, AuditTableConfig::canonical());
    }

    private function fixedClock(): ClockInterface
    {
        return new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-05 12:00:00');
            }
        };
    }
}
