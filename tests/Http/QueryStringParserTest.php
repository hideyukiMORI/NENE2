<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\QueryStringParser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class QueryStringParserTest extends TestCase
{
    private function request(string $query = ''): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://example.test/?' . $query);
    }

    // ------------------------------------------------------------------ string()

    public function testStringReturnsValueWhenPresent(): void
    {
        self::assertSame('tech', QueryStringParser::string($this->request('category=tech'), 'category'));
    }

    public function testStringReturnsNullWhenAbsent(): void
    {
        self::assertNull(QueryStringParser::string($this->request(), 'category'));
    }

    public function testStringReturnsNullForEmptyString(): void
    {
        self::assertNull(QueryStringParser::string($this->request('category='), 'category'));
    }

    public function testStringReturnsNullForUnrelatedKey(): void
    {
        self::assertNull(QueryStringParser::string($this->request('other=value'), 'category'));
    }

    // ------------------------------------------------------------------ int()

    public function testIntReturnsValueWhenPresent(): void
    {
        self::assertSame(42, QueryStringParser::int($this->request('page=42'), 'page'));
    }

    public function testIntReturnsNullWhenAbsent(): void
    {
        self::assertNull(QueryStringParser::int($this->request(), 'page'));
    }

    public function testIntReturnsNullForEmptyString(): void
    {
        self::assertNull(QueryStringParser::int($this->request('page='), 'page'));
    }

    public function testIntReturnsNullForNonNumericString(): void
    {
        self::assertNull(QueryStringParser::int($this->request('page=abc'), 'page'));
    }

    public function testIntReturnsNullForFloat(): void
    {
        self::assertNull(QueryStringParser::int($this->request('page=1.5'), 'page'));
    }

    public function testIntSupportsNegativeValues(): void
    {
        self::assertSame(-5, QueryStringParser::int($this->request('offset=-5'), 'offset'));
    }

    // ------------------------------------------------------------------ bool()

    public function testBoolReturnsTrueForTruthyString(): void
    {
        self::assertTrue(QueryStringParser::bool($this->request('is_read=true'), 'is_read'));
        self::assertTrue(QueryStringParser::bool($this->request('is_read=1'), 'is_read'));
        self::assertTrue(QueryStringParser::bool($this->request('is_read=yes'), 'is_read'));
    }

    public function testBoolReturnsFalseForFalsyStrings(): void
    {
        self::assertFalse(QueryStringParser::bool($this->request('is_read=false'), 'is_read'));
        self::assertFalse(QueryStringParser::bool($this->request('is_read=0'), 'is_read'));
        self::assertFalse(QueryStringParser::bool($this->request('is_read=no'), 'is_read'));
    }

    public function testBoolReturnsNullWhenAbsent(): void
    {
        self::assertNull(QueryStringParser::bool($this->request(), 'is_read'));
    }

    public function testBoolReturnsNullForEmptyString(): void
    {
        // ?is_read= is treated as absent
        self::assertNull(QueryStringParser::bool($this->request('is_read='), 'is_read'));
    }
}
