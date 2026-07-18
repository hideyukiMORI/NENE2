<?php

declare(strict_types=1);

namespace Nene2\Tests\Config;

use Nene2\Auth\GuardedJwtSecretResolver;
use Nene2\Auth\JwtSecretException;
use Nene2\Config\AppConfig;
use Nene2\Config\AppEnvironment;
use Nene2\Config\ConfigException;
use Nene2\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadsDefaultAppConfig(): void
    {
        // APP_ENV is cleared so the environment assertion exercises the canonical
        // default deterministically in every environment (#1526). With nothing set,
        // that default is production — secure by default (M-2).
        $config = $this->withoutEnvironmentKeys(
            ['APP_ENV'],
            fn (): AppConfig => (new ConfigLoader($this->emptyProjectRoot()))->load(),
        );

        self::assertSame(AppEnvironment::Production, $config->environment);
        self::assertFalse($config->debug);
        self::assertSame('NENE2', $config->name);
        self::assertNull($config->machineApiKey);
        self::assertFalse($config->database->usesUrl());
        self::assertSame('local', $config->database->environment);
        self::assertSame('mysql', $config->database->adapter);
        self::assertNotEmpty($config->database->host);   // env-provided in Docker (DB_HOST=mysql)
        self::assertSame(3306, $config->database->port);
        self::assertSame('nene2', $config->database->name);
        self::assertSame('nene2', $config->database->user);
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

    // --- APP_ENV secure by default (audit M-2) ------------------------------

    public function testUnsetAppEnvResolvesToProductionAndBlocksDevSecret(): void
    {
        // 未設定 → 防御作動: with APP_ENV unset the config resolves to production, so even
        // an explicit dev-secret opt-in plus an injected secret fails closed — the
        // forgotten-APP_ENV footgun can no longer silently unlock the dev path.
        $config = $this->withoutEnvironmentKeys(
            ['APP_ENV'],
            fn (): AppConfig => (new ConfigLoader($this->emptyProjectRoot()))->load([
                'NENE2_ALLOW_DEV_SECRET' => '1',
            ]),
        );

        self::assertSame(AppEnvironment::Production, $config->environment);

        $this->expectException(JwtSecretException::class);
        GuardedJwtSecretResolver::fromConfig($config, 'injected-dev-secret');
    }

    public function testExplicitLocalAppEnvKeepsDevSecretPath(): void
    {
        // 明示 local → 従来どおり: an explicit APP_ENV=local preserves the development
        // opt-in, so the injected dev secret is still returned.
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_ENV' => 'local',
            'NENE2_ALLOW_DEV_SECRET' => '1',
        ]);

        self::assertSame(AppEnvironment::Local, $config->environment);
        self::assertSame(
            'injected-dev-secret',
            GuardedJwtSecretResolver::fromConfig($config, 'injected-dev-secret'),
        );
    }

    public function testExplicitProductionAppEnvStaysFailClosed(): void
    {
        // production → fail-closed 維持: unchanged behaviour for an explicit production env.
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'APP_ENV' => 'production',
            'NENE2_ALLOW_DEV_SECRET' => '1',
        ]);

        self::assertSame(AppEnvironment::Production, $config->environment);

        $this->expectException(JwtSecretException::class);
        GuardedJwtSecretResolver::fromConfig($config, 'injected-dev-secret');
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

    public function testDefaultProblemDetailsBaseUrl(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load();

        self::assertSame('https://nene2.dev/problems/', $config->problemDetailsBaseUrl);
    }

    public function testCustomProblemDetailsBaseUrlOverride(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'PROBLEM_DETAILS_BASE_URL' => 'https://api.example.com/problems/',
        ]);

        self::assertSame('https://api.example.com/problems/', $config->problemDetailsBaseUrl);
    }

    public function testEmptyProblemDetailsBaseUrlFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('PROBLEM_DETAILS_BASE_URL must not be empty.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'PROBLEM_DETAILS_BASE_URL' => '   ',
        ]);
    }

    public function testSqliteAdapterDoesNotRequireHostUserOrCharset(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_ADAPTER' => 'sqlite',
            'DB_NAME' => '/tmp/test.sqlite',
            'DB_HOST' => '',
            'DB_USER' => '',
            'DB_CHARSET' => '',
        ]);

        self::assertSame('sqlite', $config->database->adapter);
        self::assertSame('/tmp/test.sqlite', $config->database->name);
    }

    public function testSqliteAdapterDoesNotValidatePort(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_ADAPTER' => 'sqlite',
            'DB_NAME' => '/tmp/test.sqlite',
            'DB_PORT' => '0',
        ]);

        self::assertSame('sqlite', $config->database->adapter);
        self::assertSame(0, $config->database->port);
    }

    public function testMysqlAdapterStillRequiresHost(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_HOST must not be empty.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_ADAPTER' => 'mysql',
            'DB_HOST' => '',
        ]);
    }

    public function testOutOfRangeDatabasePortFailsFastForMysql(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_PORT must be between 1 and 65535.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DB_ADAPTER' => 'mysql',
            'DB_PORT' => '65536',
        ]);
    }

    public function testAllowDevSecretDefaultsToFalse(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load();

        self::assertFalse($config->allowDevSecret);
    }

    /**
     * @return list<array{string}>
     */
    public static function truthyDevSecretOptInValues(): array
    {
        return [['1'], ['true'], ['yes'], ['YES'], ['  true  ']];
    }

    #[DataProvider('truthyDevSecretOptInValues')]
    public function testAllowDevSecretIsTrueForStrictTruthyValues(string $value): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'NENE2_ALLOW_DEV_SECRET' => $value,
        ]);

        self::assertTrue($config->allowDevSecret);
    }

    /**
     * @return list<array{string}>
     */
    public static function nonTruthyDevSecretOptInValues(): array
    {
        return [['0'], ['false'], ['no'], ['off'], ['on'], ['2'], ['maybe'], ['']];
    }

    #[DataProvider('nonTruthyDevSecretOptInValues')]
    public function testAllowDevSecretIsFalseForNonStrictTruthyValues(string $value): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'NENE2_ALLOW_DEV_SECRET' => $value,
        ]);

        self::assertFalse($config->allowDevSecret);
    }

    public function testPartialConstructorDefaultsLayerOverCanonicalDefaults(): void
    {
        // A consumer that preseeds the loader with a partial defaults map (omitting keys
        // it does not care about, e.g. NENE2_ALLOW_DEV_SECRET) must not trigger a
        // TypeError: unspecified keys fall back to the canonical defaults, and the
        // dev-secret opt-in stays opted out.
        //
        // load() lets real environment values override constructor defaults by design,
        // and this repo's compose.yaml injects DB_NAME=nene2 into the app container —
        // which would shadow the ':memory:' default under test (#1526). Clear the
        // interfering keys for the duration of the load so this exercises the
        // constructor-defaults path in every environment.
        $config = $this->withoutEnvironmentKeys(
            ['APP_ENV', 'DB_ADAPTER', 'DB_NAME', 'NENE2_ALLOW_DEV_SECRET'],
            fn (): AppConfig => (new ConfigLoader($this->emptyProjectRoot(), [
                'DB_ADAPTER' => 'sqlite',
                'DB_NAME' => ':memory:',
            ]))->load(),
        );

        self::assertSame('sqlite', $config->database->adapter);
        self::assertSame(':memory:', $config->database->name);
        self::assertFalse($config->allowDevSecret);
        // APP_ENV is cleared above, so this exercises the secure-by-default fallback (M-2).
        self::assertSame(AppEnvironment::Production, $config->environment);
    }

    public function testDemoConfigDefaultsAreOffAndInvoiceMeasured(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load();

        self::assertFalse($config->demo->demoMode);
        self::assertSame('demo-', $config->demo->slugPrefix);
        self::assertSame(3, $config->demo->ttlHours);
        self::assertSame(200, $config->demo->maxOrgs);
        self::assertSame(5, $config->demo->slugAttempts);
    }

    public function testDemoConfigOverrides(): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DEMO_MODE' => '1',
            'DEMO_SLUG_PREFIX' => 'trial-',
            'DEMO_TTL_HOURS' => '12',
            'DEMO_MAX_ORGS' => '50',
            'DEMO_SLUG_ATTEMPTS' => '8',
        ]);

        self::assertTrue($config->demo->demoMode);
        self::assertSame('trial-', $config->demo->slugPrefix);
        self::assertSame(12, $config->demo->ttlHours);
        self::assertSame(50, $config->demo->maxOrgs);
        self::assertSame(8, $config->demo->slugAttempts);
    }

    /**
     * @return list<array{string}>
     */
    public static function nonTruthyDemoModeValues(): array
    {
        // Same strict opt-in parse as NENE2_ALLOW_DEV_SECRET: the demo route creates
        // organizations unauthenticated, so a typo must leave demo mode OFF.
        return [['0'], ['false'], ['on'], ['enabled'], ['2'], ['']];
    }

    #[DataProvider('nonTruthyDemoModeValues')]
    public function testDemoModeStaysOffForNonStrictTruthyValues(string $value): void
    {
        $config = (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DEMO_MODE' => $value,
        ]);

        self::assertFalse($config->demo->demoMode);
    }

    public function testNonIntegerDemoTtlFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_TTL_HOURS must be an integer.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DEMO_TTL_HOURS' => 'soon',
        ]);
    }

    public function testZeroDemoMaxOrgsFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_MAX_ORGS must be a positive integer.');

        (new ConfigLoader($this->emptyProjectRoot()))->load([
            'DEMO_MAX_ORGS' => '0',
        ]);
    }

    private function emptyProjectRoot(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * Runs $callback with the given keys removed from $_SERVER / $_ENV, restoring
     * the original values afterwards (even when the callback throws).
     *
     * @template T
     *
     * @param list<string> $keys
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withoutEnvironmentKeys(array $keys, callable $callback): mixed
    {
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [
                array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            ];
            unset($_SERVER[$key], $_ENV[$key]);
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $key => [$serverValue, $envValue]) {
                if ($serverValue !== null) {
                    $_SERVER[$key] = $serverValue;
                }

                if ($envValue !== null) {
                    $_ENV[$key] = $envValue;
                }
            }
        }
    }
}
