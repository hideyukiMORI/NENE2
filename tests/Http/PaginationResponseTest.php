<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\PaginationResponse;
use PHPUnit\Framework\TestCase;

final class PaginationResponseTest extends TestCase
{
    public function testToArrayWithoutTotal(): void
    {
        $response = new PaginationResponse(
            items:  [['id' => 1], ['id' => 2]],
            limit:  20,
            offset: 0,
        );

        $array = $response->toArray();

        self::assertSame([['id' => 1], ['id' => 2]], $array['items']);
        self::assertSame(20, $array['limit']);
        self::assertSame(0, $array['offset']);
        self::assertArrayNotHasKey('total', $array);
    }

    public function testToArrayWithTotal(): void
    {
        $response = new PaginationResponse(
            items:  [['id' => 1]],
            limit:  10,
            offset: 20,
            total:  42,
        );

        $array = $response->toArray();

        self::assertSame(10, $array['limit']);
        self::assertSame(20, $array['offset']);
        self::assertSame(42, $array['total']);
    }

    public function testTotalNullOmitsKey(): void
    {
        $response = new PaginationResponse(items: [], limit: 20, offset: 0, total: null);

        self::assertArrayNotHasKey('total', $response->toArray());
    }

    public function testTotalZeroIsIncluded(): void
    {
        $response = new PaginationResponse(items: [], limit: 20, offset: 0, total: 0);

        self::assertSame(0, $response->toArray()['total']);
    }
}
