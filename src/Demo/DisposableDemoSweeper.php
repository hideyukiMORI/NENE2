<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nene2\Http\ClockInterface;

/**
 * Decides which disposable demo organizations to destroy and delegates the
 * destruction to the product's {@see DisposableOrgReaperInterface}.
 *
 * Two independent criteria, mirroring the invoice sweep script this generalizes:
 *
 * 1. **TTL** — orgs created more than {@see DemoConfig::$ttlHours} ago are expired.
 * 2. **Overflow** — of all existing demo orgs, only the newest
 *    {@see DemoConfig::$maxOrgs} are kept; older ones are reaped even when not
 *    yet expired (runaway/DoS insurance).
 *
 * The caller supplies the current demo org list (see {@see DemoOrgRecord} for the
 * query shape) and typically runs this from an hourly cron. Reaping is not
 * wrapped in a try/catch: {@see DisposableOrgReaperInterface::reap()} is
 * contractually idempotent (a concurrently vanished org is success), so any
 * exception that does escape is a real defect that should fail the run visibly.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class DisposableDemoSweeper
{
    public function __construct(
        private DemoConfig $config,
        private DisposableOrgReaperInterface $reaper,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<DemoOrgRecord> $demoOrgs Every currently existing demo org
     *        (already filtered to the demo slug prefix by the caller's query).
     */
    public function sweep(array $demoOrgs): SweepReport
    {
        $cutoff = $this->clock->now()->getTimestamp() - ($this->config->ttlHours * 3600);

        $expired = [];

        foreach ($demoOrgs as $org) {
            if ($org->createdAt->getTimestamp() < $cutoff) {
                $expired[] = $org->orgId;
            }
        }

        // Keep the newest $maxOrgs (creation time descending, id as tiebreaker);
        // everything past the ceiling overflows regardless of TTL.
        $byNewest = $demoOrgs;
        usort($byNewest, static function (DemoOrgRecord $a, DemoOrgRecord $b): int {
            return [$b->createdAt->getTimestamp(), $b->orgId] <=> [$a->createdAt->getTimestamp(), $a->orgId];
        });

        $overflow = array_map(
            static fn (DemoOrgRecord $org): int => $org->orgId,
            array_slice($byNewest, $this->config->maxOrgs),
        );

        $targets = array_values(array_unique([...$expired, ...$overflow]));

        foreach ($targets as $orgId) {
            $this->reaper->reap($orgId);
        }

        return new SweepReport($expired, $overflow, $targets);
    }
}
