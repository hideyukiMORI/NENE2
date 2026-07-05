<?php

declare(strict_types=1);

namespace Nene2\Audit;

/**
 * Append-only persistence for {@see AuditEvent} records (ADR 0014).
 *
 * The audit trail is immutable: this interface exposes {@see append()} but no
 * update or delete. Reads go through {@see query()} / {@see count()} with a
 * common {@see AuditQuery} filter. Product-specific read concerns (actor email
 * joins, CSV export, list routes) live in the product's own read layer and are
 * intentionally **not** part of this interface.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
interface AuditEventRepositoryInterface
{
    /**
     * Appends one audit event. Never updates or deletes existing rows.
     */
    public function append(AuditEvent $event): void;

    /**
     * @return list<AuditEvent>
     */
    public function query(AuditQuery $query, int $limit, int $offset): array;

    public function count(AuditQuery $query): int;
}
