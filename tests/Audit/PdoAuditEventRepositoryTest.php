<?php

declare(strict_types=1);

namespace Nene2\Tests\Audit;

use Nene2\Audit\AuditEvent;
use Nene2\Audit\AuditPayloadMode;
use Nene2\Audit\AuditQuery;
use Nene2\Audit\AuditTableConfig;
use Nene2\Audit\PdoAuditEventRepository;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Testing\DatabaseTestKit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdoAuditEventRepositoryTest extends TestCase
{
    private string $path = '';

    private DatabaseQueryExecutorInterface $executor;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/' . uniqid('audit-', true) . '.sqlite';
        $this->executor = DatabaseTestKit::sqlite($this->path)->queryExecutor;
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testAppendAndQueryRoundTripCanonicalWithUnicode(): void
    {
        $this->createCanonicalTable();
        $repo = new PdoAuditEventRepository($this->executor, AuditTableConfig::canonical());

        $repo->append(new AuditEvent(
            action: 'invoice.updated',
            entityType: 'invoice',
            entityId: 42,
            actorId: 7,
            organizationId: 3,
            before: ['title' => '請求書', 'total' => 1000],
            after: ['title' => '請求書（改訂）', 'total' => 1200],
            metadata: ['request_id' => 'req-1', 'source' => 'api'],
            occurredAt: '2026-07-05 10:00:00',
        ));

        $events = $repo->query(new AuditQuery(organizationId: 3), 10, 0);

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('invoice.updated', $event->action);
        self::assertSame('invoice', $event->entityType);
        self::assertSame('42', (string) $event->entityId);
        self::assertSame('7', (string) $event->actorId);
        self::assertSame(['title' => '請求書', 'total' => 1000], $event->before);
        self::assertSame(['title' => '請求書（改訂）', 'total' => 1200], $event->after);
        self::assertSame(['request_id' => 'req-1', 'source' => 'api'], $event->metadata);
        self::assertSame('2026-07-05 10:00:00', $event->occurredAt);
        self::assertIsInt($event->id);

        // Unicode is stored unescaped (JSON_UNESCAPED_UNICODE).
        $raw = $this->executor->fetchOne('SELECT before_json FROM audit_events LIMIT 1');
        self::assertNotNull($raw);
        self::assertIsString($raw['before_json']);
        self::assertStringContainsString('請求書', $raw['before_json']);
    }

    public function testAppendUsesAutoIncrementIdWhenConfigured(): void
    {
        $this->createCanonicalTable();
        $repo = new PdoAuditEventRepository($this->executor, AuditTableConfig::canonical());

        $repo->append(new AuditEvent(action: 'a.one', entityType: 't', occurredAt: '2026-07-05 10:00:00'));
        $repo->append(new AuditEvent(action: 'a.two', entityType: 't', occurredAt: '2026-07-05 10:00:01'));

        $events = $repo->query(new AuditQuery(sortColumn: 'id', sortDirection: 'ASC'), 10, 0);

        self::assertSame([1, 2], array_map(static fn (AuditEvent $e): int|string|null => $e->id, $events));
    }

    public function testCreateAndDeleteSnapshotsMapToNullBranches(): void
    {
        $this->createCanonicalTable();
        $repo = new PdoAuditEventRepository($this->executor, AuditTableConfig::canonical());

        $repo->append(new AuditEvent(action: 'x.created', entityType: 'x', after: ['v' => 1], occurredAt: '2026-07-05 10:00:00'));
        $repo->append(new AuditEvent(action: 'x.deleted', entityType: 'x', before: ['v' => 1], occurredAt: '2026-07-05 10:00:01'));

        $events = $repo->query(new AuditQuery(sortColumn: 'id', sortDirection: 'ASC'), 10, 0);

        self::assertNull($events[0]->before);
        self::assertSame(['v' => 1], $events[0]->after);
        self::assertSame(['v' => 1], $events[1]->before);
        self::assertNull($events[1]->after);
    }

    public function testQueryFiltersAndSortDirection(): void
    {
        $this->createCanonicalTable();
        $repo = new PdoAuditEventRepository($this->executor, AuditTableConfig::canonical());

        $repo->append(new AuditEvent(action: 'a', entityType: 'invoice', entityId: 1, actorId: 5, organizationId: 1, occurredAt: '2026-07-01 00:00:00'));
        $repo->append(new AuditEvent(action: 'b', entityType: 'payment', entityId: 2, actorId: 6, organizationId: 1, occurredAt: '2026-07-02 00:00:00'));
        $repo->append(new AuditEvent(action: 'c', entityType: 'invoice', entityId: 3, actorId: 5, organizationId: 2, occurredAt: '2026-07-03 00:00:00'));

        // organization scope
        self::assertSame(2, $repo->count(new AuditQuery(organizationId: 1)));

        // entityType + actorId filter
        $filtered = $repo->query(new AuditQuery(organizationId: 1, entityType: 'invoice', actorId: 5), 10, 0);
        self::assertCount(1, $filtered);
        self::assertSame('a', $filtered[0]->action);

        // date window
        $window = $repo->query(new AuditQuery(occurredFrom: '2026-07-02 00:00:00', occurredTo: '2026-07-02 23:59:59'), 10, 0);
        self::assertCount(1, $window);
        self::assertSame('b', $window[0]->action);

        // sort ascending vs descending by occurred_at
        $asc = $repo->query(new AuditQuery(sortColumn: 'occurred_at', sortDirection: 'ASC'), 10, 0);
        $desc = $repo->query(new AuditQuery(sortColumn: 'occurred_at', sortDirection: 'DESC'), 10, 0);
        self::assertSame(['a', 'b', 'c'], array_map(static fn (AuditEvent $e): string => $e->action, $asc));
        self::assertSame(['c', 'b', 'a'], array_map(static fn (AuditEvent $e): string => $e->action, $desc));
    }

    public function testColumnMapAndSinglePayloadModeSwap(): void
    {
        // A product table with different names, a single JSON payload column, no metadata column.
        $this->executor->execute(
            'CREATE TABLE clear_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NULL,
                actor_user_id VARCHAR(64) NULL,
                organization_id VARCHAR(64) NULL,
                payload_json TEXT NOT NULL,
                occurred_at DATETIME NOT NULL
            )',
        );

        $config = new AuditTableConfig(
            table: 'clear_audit',
            mode: AuditPayloadMode::SinglePayload,
            actionColumn: 'event_type',
            actorColumn: 'actor_user_id',
            metadataColumn: null,
            beforeColumn: null,
            afterColumn: null,
            payloadColumn: 'payload_json',
        );
        $repo = new PdoAuditEventRepository($this->executor, $config);

        $repo->append(new AuditEvent(
            action: 'payment.reconciled',
            entityType: 'payment',
            entityId: 9,
            actorId: 4,
            organizationId: 1,
            before: ['status' => 'pending'],
            after: ['status' => 'reconciled'],
            metadata: ['ip' => '10.0.0.1'],
            occurredAt: '2026-07-05 12:00:00',
        ));

        // before / after / metadata are folded into and unfolded from the single column.
        $events = $repo->query(new AuditQuery(organizationId: 1), 10, 0);
        self::assertCount(1, $events);
        self::assertSame('payment.reconciled', $events[0]->action);
        self::assertSame(['status' => 'pending'], $events[0]->before);
        self::assertSame(['status' => 'reconciled'], $events[0]->after);
        self::assertSame(['ip' => '10.0.0.1'], $events[0]->metadata);

        // Physically, one JSON object holds all three sub-parts.
        $raw = $this->executor->fetchOne('SELECT payload_json FROM clear_audit LIMIT 1');
        self::assertNotNull($raw);
        self::assertIsString($raw['payload_json']);
        $decoded = json_decode($raw['payload_json'], true);
        self::assertSame(['before', 'after', 'metadata'], array_keys((array) $decoded));
    }

    public function testUlidStringIdConfigWritesCallerSuppliedId(): void
    {
        $this->executor->execute(
            'CREATE TABLE ulid_audit (
                id CHAR(26) PRIMARY KEY,
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

        $config = new AuditTableConfig(
            table: 'ulid_audit',
            mode: AuditPayloadMode::BeforeAfter,
            idIsAutoIncrement: false,
        );
        $repo = new PdoAuditEventRepository($this->executor, $config);

        $repo->append(new AuditEvent(
            action: 'document.uploaded',
            entityType: 'document',
            entityId: '01HXULIDENTITY0000000000000',
            organizationId: 'org-1',
            occurredAt: '2026-07-05 12:00:00',
            id: '01HXAUDITEVENT00000000000000',
        ));

        $events = $repo->query(new AuditQuery(organizationId: 'org-1'), 10, 0);
        self::assertCount(1, $events);
        self::assertSame('01HXAUDITEVENT00000000000000', $events[0]->id);
    }

    public function testInterfaceIsAppendOnly(): void
    {
        // The persistence contract exposes no update/delete — the audit trail is immutable.
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(\Nene2\Audit\AuditEventRepositoryInterface::class))->getMethods(),
        );

        sort($methods);
        self::assertSame(['append', 'count', 'query'], $methods);
    }

    private function createCanonicalTable(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema/audit_events.sql');

        // Drop comment lines so the statement splitter sees only DDL.
        $lines = array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(trim($line), '--'),
        );

        foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $statement) {
            $this->executor->execute($statement);
        }
    }
}
