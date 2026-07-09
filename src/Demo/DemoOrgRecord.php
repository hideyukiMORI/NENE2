<?php

declare(strict_types=1);

namespace Nene2\Demo;

use DateTimeImmutable;

/**
 * One existing demo organization as seen by {@see DisposableDemoSweeper} — the
 * minimal shape (id + creation instant) the TTL and overflow decisions need.
 *
 * The caller queries these from its own schema (e.g. `SELECT id, created_at FROM
 * organizations WHERE slug LIKE 'demo-%'`) so the framework never learns the
 * tenant table's layout. `$createdAt` must be in UTC, matching
 * {@see \Nene2\Http\ClockInterface}.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class DemoOrgRecord
{
    public function __construct(
        public int $orgId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
