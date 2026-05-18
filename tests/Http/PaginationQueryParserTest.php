<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\PaginationQuery;
use Nene2\Http\PaginationQueryParser;
use Nene2\Validation\ValidationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class PaginationQueryParserTest extends TestCase
{
    private function request(string $query = ''): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return $factory->createServerRequest('GET', 'https://example.test/?' . $query);
    }

    public function testDefaultsWhenNoQueryParams(): void
    {
        $result = PaginationQueryParser::parse($this->request());

        self::assertSame(20, $result->limit);
        self::assertSame(0, $result->offset);
    }

    public function testParsesExplicitLimitAndOffset(): void
    {
        $result = PaginationQueryParser::parse($this->request('limit=50&offset=100'));

        self::assertSame(50, $result->limit);
        self::assertSame(100, $result->offset);
    }

    public function testReturnsPaginationQueryInstance(): void
    {
        $result = PaginationQueryParser::parse($this->request('limit=10&offset=5'));

        self::assertInstanceOf(PaginationQuery::class, $result);
    }

    public function testCustomDefaultLimit(): void
    {
        $result = PaginationQueryParser::parse($this->request(), defaultLimit: 5);

        self::assertSame(5, $result->limit);
    }

    public function testCustomMaxLimit(): void
    {
        $result = PaginationQueryParser::parse($this->request('limit=200'), maxLimit: 500);

        self::assertSame(200, $result->limit);
    }

    public function testThrowsWhenLimitTooLow(): void
    {
        $this->expectException(ValidationException::class);

        PaginationQueryParser::parse($this->request('limit=0'));
    }

    public function testThrowsWhenLimitExceedsMax(): void
    {
        $this->expectException(ValidationException::class);

        PaginationQueryParser::parse($this->request('limit=101'));
    }

    public function testThrowsWhenOffsetNegative(): void
    {
        $this->expectException(ValidationException::class);

        PaginationQueryParser::parse($this->request('offset=-1'));
    }

    public function testThrowsBothErrorsWhenBothInvalid(): void
    {
        try {
            PaginationQueryParser::parse($this->request('limit=0&offset=-1'));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertCount(2, $e->errors());
        }
    }
}
