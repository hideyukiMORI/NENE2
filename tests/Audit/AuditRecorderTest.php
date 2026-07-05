<?php

declare(strict_types=1);

namespace Nene2\Tests\Audit;

use DateTimeImmutable;
use Nene2\Audit\AuditEvent;
use Nene2\Audit\AuditEventRepositoryInterface;
use Nene2\Audit\AuditQuery;
use Nene2\Audit\AuditRecorder;
use Nene2\Http\ClockInterface;
use Nene2\Http\RequestScopedHolder;
use PHPUnit\Framework\TestCase;

final class AuditRecorderTest extends TestCase
{
    public function testFillsOccurredAtFromClockWhenAbsent(): void
    {
        $repository = self::spyRepository();
        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'));

        $recorder->record(new AuditEvent(action: 'invoice.issued', entityType: 'invoice', entityId: 7));

        self::assertSame('2026-07-05 09:30:00', $repository->last()->occurredAt);
    }

    public function testKeepsCallerSuppliedOccurredAt(): void
    {
        $repository = self::spyRepository();
        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'));

        $recorder->record(new AuditEvent(
            action: 'invoice.issued',
            entityType: 'invoice',
            entityId: 7,
            occurredAt: '2020-01-01 00:00:00',
        ));

        self::assertSame('2020-01-01 00:00:00', $repository->last()->occurredAt);
    }

    public function testFillsOrganizationFromHolderWhenAbsent(): void
    {
        $repository = self::spyRepository();
        /** @var RequestScopedHolder<string|int> $holder */
        $holder = new RequestScopedHolder();
        $holder->set('org-42');

        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'), $holder);
        $recorder->record(new AuditEvent(action: 'user.created', entityType: 'user', entityId: 'u1'));

        self::assertSame('org-42', $repository->last()->organizationId);
    }

    public function testKeepsCallerSuppliedOrganizationOverHolder(): void
    {
        $repository = self::spyRepository();
        /** @var RequestScopedHolder<string|int> $holder */
        $holder = new RequestScopedHolder();
        $holder->set('org-from-holder');

        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'), $holder);
        $recorder->record(new AuditEvent(
            action: 'user.created',
            entityType: 'user',
            entityId: 'u1',
            organizationId: 'org-explicit',
        ));

        self::assertSame('org-explicit', $repository->last()->organizationId);
    }

    public function testLeavesOrganizationNullWhenNoHolderAndNoValue(): void
    {
        $repository = self::spyRepository();
        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'));

        $recorder->record(new AuditEvent(action: 'system.ping', entityType: 'system'));

        self::assertNull($repository->last()->organizationId);
    }

    public function testDoesNotReadHolderThatWasNeverSet(): void
    {
        $repository = self::spyRepository();
        // An unset holder throws on get(); the recorder must guard with isSet().
        /** @var RequestScopedHolder<string|int> $holder */
        $holder = new RequestScopedHolder();

        $recorder = new AuditRecorder($repository, self::clockAt('2026-07-05 09:30:00'), $holder);
        $recorder->record(new AuditEvent(action: 'system.ping', entityType: 'system'));

        self::assertNull($repository->last()->organizationId);
    }

    private static function clockAt(string $instant): ClockInterface
    {
        return new class ($instant) implements ClockInterface {
            public function __construct(private string $instant)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->instant);
            }
        };
    }

    private static function spyRepository(): SpyAuditEventRepository
    {
        return new SpyAuditEventRepository();
    }
}

/**
 * In-memory {@see AuditEventRepositoryInterface} capturing appended events.
 */
final class SpyAuditEventRepository implements AuditEventRepositoryInterface
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function query(AuditQuery $query, int $limit, int $offset): array
    {
        return $this->events;
    }

    public function count(AuditQuery $query): int
    {
        return count($this->events);
    }

    public function last(): AuditEvent
    {
        return $this->events[count($this->events) - 1];
    }
}
