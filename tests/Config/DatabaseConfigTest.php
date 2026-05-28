<?php

declare(strict_types=1);

namespace Nene2\Tests\Config;

use Nene2\Config\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testSqliteFactoryFillsRequiredFields(): void
    {
        $config = DatabaseConfig::sqlite('/tmp/example.sqlite');

        self::assertSame('sqlite', $config->adapter);
        self::assertSame('/tmp/example.sqlite', $config->name);
        self::assertSame('local', $config->environment);
        self::assertNull($config->url);
        self::assertFalse($config->usesUrl());
    }

    public function testSqliteFactoryAcceptsCustomEnvironment(): void
    {
        $config = DatabaseConfig::sqlite(':memory:', 'test');

        self::assertSame('test', $config->environment);
        self::assertSame(':memory:', $config->name);
    }
}
