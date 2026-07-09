<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * What one {@see DisposableDemoSweeper} run decided and did.
 *
 * An org can be both expired and overflowing; it appears in both id lists but is
 * reaped once, so `$reapedOrgIds` is the deduplicated union (in reap order:
 * expired first, then overflow).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class SweepReport
{
    /**
     * @param list<int> $expiredOrgIds Orgs older than the TTL.
     * @param list<int> $overflowOrgIds Oldest orgs beyond the ceiling, after keeping the newest `maxOrgs`.
     * @param list<int> $reapedOrgIds Every org actually handed to the reaper (deduplicated union).
     */
    public function __construct(
        public array $expiredOrgIds,
        public array $overflowOrgIds,
        public array $reapedOrgIds,
    ) {
    }
}
