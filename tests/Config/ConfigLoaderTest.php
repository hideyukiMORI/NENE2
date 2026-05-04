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
    }

    public function testExplicitOverridesWinOverDefaults(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'APP_NAME' => 'NENE2 Test',
        ]);

        self::assertSame(AppEnvironment::Test, $config->environment);
        self::assertTrue($config->debug);
        self::assertSame('NENE2 Test', $config->name);
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

    private function emptyProjectRoot(): string
    {
        return sys_get_temp_dir();
    }
}
