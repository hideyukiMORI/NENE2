<?php

declare(strict_types=1);

namespace Nene2\Tests\Audit;

use InvalidArgumentException;
use Nene2\Audit\AuditQuery;
use PHPUnit\Framework\TestCase;

final class AuditQueryTest extends TestCase
{
    public function testDefaultsToOccurredAtDescending(): void
    {
        $query = new AuditQuery();

        self::assertSame('occurred_at', $query->sortColumn);
        self::assertSame('DESC', $query->sortDirection);
    }

    public function testAcceptsEveryWhitelistedSortColumn(): void
    {
        foreach (AuditQuery::SORT_COLUMNS as $column) {
            $query = new AuditQuery(sortColumn: $column);
            self::assertSame($column, $query->sortColumn);
        }
    }

    public function testNormalisesSortDirectionToUpperCase(): void
    {
        self::assertSame('ASC', (new AuditQuery(sortDirection: 'asc'))->sortDirection);
        self::assertSame('DESC', (new AuditQuery(sortDirection: 'desc'))->sortDirection);
    }

    public function testRejectsSortColumnOutsideWhitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sort column/');

        // A classic injection attempt must never survive the boundary.
        new AuditQuery(sortColumn: 'occurred_at; DROP TABLE audit_events');
    }

    public function testRejectsSortDirectionOutsideWhitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sort direction/');

        new AuditQuery(sortDirection: 'RANDOM()');
    }
}
