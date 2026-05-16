<?php

declare(strict_types=1);

namespace Nene2\Tests\Log;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Nene2\Log\RequestIdHolder;
use Nene2\Log\RequestIdProcessor;
use PHPUnit\Framework\TestCase;

final class RequestIdProcessorTest extends TestCase
{
    public function testAddsRequestIdToExtra(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('abc123');

        $processor = new RequestIdProcessor($holder);
        $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'msg');
        $result = $processor($record);

        self::assertSame('abc123', $result->extra['request_id']);
    }

    public function testSkipsExtraWhenHolderIsEmpty(): void
    {
        $holder = new RequestIdHolder();
        $processor = new RequestIdProcessor($holder);
        $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'msg');
        $result = $processor($record);

        self::assertArrayNotHasKey('request_id', $result->extra);
    }

    public function testPreservesExistingExtraFields(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-1');

        $processor = new RequestIdProcessor($holder);
        $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'msg', extra: ['foo' => 'bar']);
        $result = $processor($record);

        self::assertSame('bar', $result->extra['foo']);
        self::assertSame('req-1', $result->extra['request_id']);
    }
}
