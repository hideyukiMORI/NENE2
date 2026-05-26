<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\ClockInterface;
use Nene2\Http\UtcClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UtcClockTest extends TestCase
{
    #[Test]
    public function implementsClockInterface(): void
    {
        self::assertInstanceOf(ClockInterface::class, new UtcClock());
    }

    #[Test]
    public function nowReturnsDateTimeImmutable(): void
    {
        $clock = new UtcClock();
        $now   = $clock->now();

        self::assertInstanceOf(\DateTimeImmutable::class, $now);
    }

    #[Test]
    public function nowIsInUtc(): void
    {
        $clock = new UtcClock();
        $now   = $clock->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    #[Test]
    public function nowIsoReturnsIso8601UtcString(): void
    {
        $clock = new UtcClock();
        $iso   = $clock->nowIso();

        // Matches "YYYY-MM-DDTHH:MM:SSZ"
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $iso,
        );
    }

    #[Test]
    public function nowIsoWithModifyShiftsTime(): void
    {
        $clock = new UtcClock();
        $base  = $clock->nowIso();
        $later = $clock->nowIso('+1 hour');

        $baseTs  = (new \DateTimeImmutable($base))->getTimestamp();
        $laterTs = (new \DateTimeImmutable($later))->getTimestamp();

        self::assertSame(3600, $laterTs - $baseTs);
    }

    #[Test]
    public function nowIsoWithNegativeModify(): void
    {
        $clock  = new UtcClock();
        $base   = $clock->nowIso();
        $before = $clock->nowIso('-30 minutes');

        $baseTs   = (new \DateTimeImmutable($base))->getTimestamp();
        $beforeTs = (new \DateTimeImmutable($before))->getTimestamp();

        self::assertSame(-1800, $beforeTs - $baseTs);
    }

    #[Test]
    public function toIsoNormalisesToUtc(): void
    {
        // +09:00 Japan time — UTC is 9 hours behind
        $jst = new \DateTimeImmutable('2026-05-26T12:00:00+09:00');
        $iso = UtcClock::toIso($jst);

        self::assertSame('2026-05-26T03:00:00Z', $iso);
    }

    #[Test]
    public function toIsoAlreadyUtcPassesThrough(): void
    {
        $utc = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $iso = UtcClock::toIso($utc);

        self::assertSame('2026-01-01T00:00:00Z', $iso);
    }

    #[Test]
    public function clockInterfaceIsReplaceableForTesting(): void
    {
        $fixed = new \DateTimeImmutable('2026-06-01T10:00:00Z');

        $stub = new class ($fixed) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $instant)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->instant;
            }
        };

        self::assertSame($fixed, $stub->now());
    }
}
