<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use LogicException;
use Nene2\Http\RequestScopedHolder;
use PHPUnit\Framework\TestCase;

final class RequestScopedHolderTest extends TestCase
{
    public function testSetAndGet(): void
    {
        /** @var RequestScopedHolder<int> $holder */
        $holder = new RequestScopedHolder();
        $holder->set(42);

        self::assertSame(42, $holder->get());
    }

    public function testIsSetReturnsFalseBeforeSet(): void
    {
        $holder = new RequestScopedHolder();

        self::assertFalse($holder->isSet());
    }

    public function testIsSetReturnsTrueAfterSet(): void
    {
        /** @var RequestScopedHolder<string> $holder */
        $holder = new RequestScopedHolder();
        $holder->set('tenant-42');

        self::assertTrue($holder->isSet());
    }

    public function testGetThrowsWhenNotInitialized(): void
    {
        $holder = new RequestScopedHolder();

        $this->expectException(LogicException::class);
        $holder->get();
    }

    public function testResetClearsValue(): void
    {
        /** @var RequestScopedHolder<string> $holder */
        $holder = new RequestScopedHolder();
        $holder->set('hello');
        $holder->reset();

        self::assertFalse($holder->isSet());
    }

    public function testResetMakesGetThrow(): void
    {
        /** @var RequestScopedHolder<string> $holder */
        $holder = new RequestScopedHolder();
        $holder->set('hello');
        $holder->reset();

        $this->expectException(LogicException::class);
        $holder->get();
    }

    public function testSetOverwritesPreviousValue(): void
    {
        /** @var RequestScopedHolder<int> $holder */
        $holder = new RequestScopedHolder();
        $holder->set(1);
        $holder->set(99);

        self::assertSame(99, $holder->get());
    }

    public function testWorksWithObjects(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';

        /** @var RequestScopedHolder<\stdClass> $holder */
        $holder = new RequestScopedHolder();
        $holder->set($obj);

        self::assertSame($obj, $holder->get());
    }
}
