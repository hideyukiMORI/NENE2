<?php

declare(strict_types=1);

namespace Nene2\Tests\Config;

use Nene2\Config\AppEnvironment;
use Nene2\Config\ConfigException;
use Nene2\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadsDefaultAppConfig(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load();

        self::assertSame(AppEnvironment::Local, $config->environment);
        self::assertFalse($config->debug);
        self::assertSame('NENE2', $config->name);
        self::assertNull($config->machineApiKey);
        self::assertFalse($config->database->usesUrl());
        self::assertSame('local', $config->database->environment);
        self::assertSame('mysql', $config->database->adapter);
        self::assertSame('127.0.0.1', $config->database->host);
        self::assertSame(3306, $config->database->port);
        self::assertSame('nene2', $config->database->name);
        self::assertSame('nene2', $config->database->user);
        self::assertSame('', $config->database->password);
        self::assertSame('utf8mb4', $config->database->charset);
    }

    public function testExplicitOverridesWinOverDefaults(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'APP_NAME' => 'NENE2 Test',
            'NENE2_MACHINE_API_KEY' => 'test-machine-key',
            'DATABASE_URL' => 'sqlite:///:memory:',
            'DB_ENV' => 'test',
            'DB_ADAPTER' => 'sqlite',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '1',
            'DB_NAME' => 'nene2_test',
            'DB_USER' => 'tester',
            'DB_PASSWORD' => 'secret',
            'DB_CHARSET' => 'utf8',
        ]);

        self::assertSame(AppEnvironment::Test, $config->environment);
        self::assertTrue($config->debug);
        self::assertSame('NENE2 Test', $config->name);
        self::assertSame('test-machine-key', $config->machineApiKey);
        self::assertSame('sqlite:///:memory:', $config->database->url);
        self::assertSame('test', $config->database->environment);
        self::assertSame('sqlite', $config->database->adapter);
        self::assertSame('localhost', $config->database->host);
        self::assertSame(1, $config->database->port);
        self::assertSame('nene2_test', $config->database->name);
        self::assertSame('tester', $config->database->user);
        self::assertSame('secret', $config->database->password);
        self::assertSame('utf8', $config->database->charset);
    }

    public function testInvalidEnvironmentFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_ENV must be one of: local, test, production.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_ENV' => 'staging',
        ]);
    }

    public function testInvalidBooleanFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_DEBUG must be a boolean value.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_DEBUG' => 'sometimes',
        ]);
    }

    public function testEmptyApplicationNameFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_NAME must not be empty.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_NAME' => '   ',
        ]);
    }

    public function testInvalidDatabasePortFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_PORT must be an integer.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_PORT' => 'mysql',
        ]);
    }

    public function testOutOfRangeDatabasePortFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_PORT must be between 1 and 65535.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_PORT' => '65536',
        ]);
    }

    public function testEmptyDatabaseNameFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_NAME must not be empty.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_NAME' => '   ',
        ]);
    }

    private function emptyProjectRoot(): string
    {
        return sys_get_temp_dir();
    }
}
