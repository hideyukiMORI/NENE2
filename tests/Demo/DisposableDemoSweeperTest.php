<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use DateTimeImmutable;
use Nene2\Demo\DemoConfig;
use Nene2\Demo\DemoOrgRecord;
use Nene2\Demo\DisposableDemoSweeper;
use Nene2\Demo\DisposableOrgReaperInterface;
use Nene2\Http\ClockInterface;
use PHPUnit\Framework\TestCase;

final class DisposableDemoSweeperTest extends TestCase
{
    private const NOW = '2026-07-09T12:00:00Z';

    public function testEmptyInputReapsNothing(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 200), $reaper);

        $report = $sweeper->sweep([]);

        self::assertSame([], $report->expiredOrgIds);
        self::assertSame([], $report->overflowOrgIds);
        self::assertSame([], $report->reapedOrgIds);
        self::assertSame([], $reaper->reaped);
    }

    public function testExpiredOrgsAreReapedFreshOnesKept(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 200), $reaper);

        $report = $sweeper->sweep([
            $this->org(1, '2026-07-09T08:59:59Z'),  // 3h1s old — expired
            $this->org(2, '2026-07-09T09:00:00Z'),  // exactly 3h old — kept (strict <)
            $this->org(3, '2026-07-09T11:30:00Z'),  // fresh — kept
        ]);

        self::assertSame([1], $report->expiredOrgIds);
        self::assertSame([], $report->overflowOrgIds);
        self::assertSame([1], $report->reapedOrgIds);
        self::assertSame([1], $reaper->reaped);
    }

    public function testOverflowReapsOldestBeyondTheCeiling(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 2), $reaper);

        $report = $sweeper->sweep([
            $this->org(1, '2026-07-09T10:00:00Z'),  // oldest of three fresh orgs
            $this->org(2, '2026-07-09T11:00:00Z'),
            $this->org(3, '2026-07-09T11:30:00Z'),
        ]);

        self::assertSame([], $report->expiredOrgIds);
        self::assertSame([1], $report->overflowOrgIds);
        self::assertSame([1], $reaper->reaped);
    }

    public function testOverflowTiebreaksEqualTimestampsByLowerIdFirst(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 1), $reaper);

        $report = $sweeper->sweep([
            $this->org(5, '2026-07-09T11:00:00Z'),
            $this->org(4, '2026-07-09T11:00:00Z'),
        ]);

        self::assertSame([4], $report->overflowOrgIds);
    }

    public function testOrgBothExpiredAndOverflowingIsReapedOnce(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 1), $reaper);

        $report = $sweeper->sweep([
            $this->org(1, '2026-07-09T07:00:00Z'),  // expired AND beyond the ceiling
            $this->org(2, '2026-07-09T11:00:00Z'),
        ]);

        self::assertSame([1], $report->expiredOrgIds);
        self::assertSame([1], $report->overflowOrgIds);
        self::assertSame([1], $report->reapedOrgIds);
        self::assertSame([1], $reaper->reaped, 'the union must be deduplicated — one reap per org');
    }

    public function testExpiredAndOverflowCombineExpiredFirst(): void
    {
        $reaper = $this->makeReaper();
        $sweeper = $this->makeSweeper(new DemoConfig(ttlHours: 3, maxOrgs: 2), $reaper);

        $report = $sweeper->sweep([
            $this->org(1, '2026-07-09T07:00:00Z'),  // expired
            $this->org(2, '2026-07-09T10:00:00Z'),  // fresh but oldest of the three kept candidates
            $this->org(3, '2026-07-09T11:00:00Z'),
            $this->org(4, '2026-07-09T11:30:00Z'),
        ]);

        self::assertSame([1], $report->expiredOrgIds);
        self::assertSame([2, 1], $report->overflowOrgIds);
        self::assertSame([1, 2], $report->reapedOrgIds);
        self::assertSame([1, 2], $reaper->reaped);
    }

    private function org(int $id, string $createdAt): DemoOrgRecord
    {
        return new DemoOrgRecord($id, new DateTimeImmutable($createdAt));
    }

    /** @return DisposableOrgReaperInterface&object{reaped: list<int>} */
    private function makeReaper(): DisposableOrgReaperInterface
    {
        return new class () implements DisposableOrgReaperInterface {
            /** @var list<int> */
            public array $reaped = [];

            public function reap(int $orgId): void
            {
                $this->reaped[] = $orgId;
            }
        };
    }

    private function makeSweeper(DemoConfig $config, DisposableOrgReaperInterface $reaper): DisposableDemoSweeper
    {
        $clock = new class (new DateTimeImmutable(self::NOW)) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };

        return new DisposableDemoSweeper($config, $reaper, $clock);
    }
}
