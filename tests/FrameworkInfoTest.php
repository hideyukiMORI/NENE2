<?php

declare(strict_types=1);

namespace Nene2\Tests;

use Nene2\FrameworkInfo;
use PHPUnit\Framework\TestCase;

final class FrameworkInfoTest extends TestCase
{
    public function testNameReturnsFrameworkName(): void
    {
        self::assertSame('NENE2', (new FrameworkInfo())->name());
    }
}
