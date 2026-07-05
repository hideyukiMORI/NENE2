<?php

declare(strict_types=1);

namespace Nene2\Audit;

use Nene2\Http\ClockInterface;
use Nene2\Http\RequestScopedHolder;

/**
 * Default {@see AuditRecorderInterface} (ADR 0014).
 *
 * Completes an {@see AuditEvent} before appending it:
 *
 * - `occurredAt` is filled from the injected {@see ClockInterface} when the caller
 *   left it null — the audit timestamp is a UTC instant from the same clock as
 *   every other "now", so it is deterministic in tests and cannot drift. This is
 *   the structural fix for the anti-pattern of a repository calling `date()`
 *   itself (nene-profile's `PdoAuditLogRepository`).
 * - `organizationId` is filled from an optional request-scoped tenant holder when
 *   the caller left it null, so a use case that already knows the tenant need not
 *   thread it through, and one that does not gets it from the request context.
 *
 * Concrete implementation detail — **outside** the public API stability guarantee
 * (ADR 0009). Depend on {@see AuditRecorderInterface}; construct this (or obtain
 * it from {@see AuditRecorderFactory}) at the composition root.
 *
 * @see AuditRecorderFactory for the transaction-atomic recorder factory.
 */
final readonly class AuditRecorder implements AuditRecorderInterface
{
    /**
     * @param RequestScopedHolder<string|int>|null $organizationHolder tenant id for events that omit it
     */
    public function __construct(
        private AuditEventRepositoryInterface $repository,
        private ClockInterface $clock,
        private ?RequestScopedHolder $organizationHolder = null,
    ) {
    }

    public function record(AuditEvent $event): void
    {
        $occurredAt = $event->occurredAt ?? $this->clock->now()->format('Y-m-d H:i:s');

        $organizationId = $event->organizationId;

        if ($organizationId === null && $this->organizationHolder !== null && $this->organizationHolder->isSet()) {
            $organizationId = $this->organizationHolder->get();
        }

        $this->repository->append($event->completed($occurredAt, $organizationId));
    }
}
