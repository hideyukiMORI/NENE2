<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\ConditionalGetHelper;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ConditionalGetHelperTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function request(string $ifNoneMatch = '', string $ifModifiedSince = ''): ServerRequestInterface
    {
        $req = $this->factory->createServerRequest('GET', 'https://example.test/resource');

        if ($ifNoneMatch !== '') {
            $req = $req->withHeader('If-None-Match', $ifNoneMatch);
        }

        if ($ifModifiedSince !== '') {
            $req = $req->withHeader('If-Modified-Since', $ifModifiedSince);
        }

        return $req;
    }

    // --- If-None-Match ---

    public function testMatchingEtagReturns304(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifNoneMatch: '"abc123"'),
            $this->factory,
            '"abc123"',
        );

        self::assertNotNull($res);
        self::assertSame(304, $res->getStatusCode());
    }

    public function testMatchingEtagResponseCarriesEtagHeader(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifNoneMatch: '"abc123"'),
            $this->factory,
            '"abc123"',
        );

        self::assertNotNull($res);
        self::assertSame('"abc123"', $res->getHeaderLine('ETag'));
    }

    public function testNonMatchingEtagReturnsNull(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifNoneMatch: '"stale"'),
            $this->factory,
            '"fresh"',
        );

        self::assertNull($res);
    }

    public function testAbsentIfNoneMatchReturnsNull(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(),
            $this->factory,
            '"abc123"',
        );

        self::assertNull($res);
    }

    // --- If-Modified-Since ---

    public function testCurrentLastModifiedReturns304(): void
    {
        $ts  = '2026-05-20T12:00:00Z';
        $res = ConditionalGetHelper::check(
            $this->request(ifModifiedSince: $ts),
            $this->factory,
            '"tag"',
            $ts,
        );

        self::assertNotNull($res);
        self::assertSame(304, $res->getStatusCode());
    }

    public function testFutureIfModifiedSinceReturns304(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifModifiedSince: '2026-05-20T13:00:00Z'),
            $this->factory,
            '"tag"',
            '2026-05-20T12:00:00Z',
        );

        self::assertNotNull($res);
        self::assertSame(304, $res->getStatusCode());
    }

    public function testOldIfModifiedSinceReturnsNull(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifModifiedSince: '2020-01-01T00:00:00Z'),
            $this->factory,
            '"tag"',
            '2026-05-20T12:00:00Z',
        );

        self::assertNull($res);
    }

    public function testAbsentIfModifiedSinceReturnsNull(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(),
            $this->factory,
            '"tag"',
            '2026-05-20T12:00:00Z',
        );

        self::assertNull($res);
    }

    public function testLastModifiedResponseCarriesLastModifiedHeader(): void
    {
        $ts  = '2026-05-20T12:00:00Z';
        $res = ConditionalGetHelper::check(
            $this->request(ifModifiedSince: $ts),
            $this->factory,
            '"tag"',
            $ts,
        );

        self::assertNotNull($res);
        self::assertSame($ts, $res->getHeaderLine('Last-Modified'));
    }

    // --- Last-Modified omitted ---

    public function testOmittedLastModifiedSkipsIfModifiedSinceCheck(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifModifiedSince: '2026-05-20T13:00:00Z'),
            $this->factory,
            '"tag"',
            // no lastModified argument
        );

        self::assertNull($res);
    }

    public function test304WithoutLastModifiedHasNoLastModifiedHeader(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifNoneMatch: '"tag"'),
            $this->factory,
            '"tag"',
        );

        self::assertNotNull($res);
        self::assertSame('', $res->getHeaderLine('Last-Modified'));
    }

    // --- If-None-Match takes priority ---

    public function testEtagMatchWhenBothHeadersPresent(): void
    {
        $res = ConditionalGetHelper::check(
            $this->request(ifNoneMatch: '"tag"', ifModifiedSince: '2020-01-01T00:00:00Z'),
            $this->factory,
            '"tag"',
            '2026-05-20T12:00:00Z',
        );

        self::assertNotNull($res);
        self::assertSame(304, $res->getStatusCode());
    }
}
