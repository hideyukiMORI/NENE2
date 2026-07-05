<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\GuardedJwtSecretResolver;
use Nene2\Auth\JwtSecretException;
use Nene2\Config\AppConfig;
use Nene2\Config\AppEnvironment;
use Nene2\Config\DatabaseConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuardedJwtSecretResolverTest extends TestCase
{
    private const CONFIGURED = 'a-real-random-secret';
    private const DEV = 'product-dev-secret';

    /**
     * A non-empty configured secret is always used, in every environment.
     *
     * @return list<array{AppEnvironment}>
     */
    public static function allEnvironments(): array
    {
        return [
            [AppEnvironment::Local],
            [AppEnvironment::Test],
            [AppEnvironment::Production],
        ];
    }

    #[DataProvider('allEnvironments')]
    public function testConfiguredSecretIsUsedInEveryEnvironment(AppEnvironment $environment): void
    {
        $resolver = new GuardedJwtSecretResolver(self::CONFIGURED, $environment, false, self::DEV);

        self::assertSame(self::CONFIGURED, $resolver->resolve());
    }

    public function testConfiguredSecretWinsEvenWhenOptInAndDevSecretPresent(): void
    {
        $resolver = new GuardedJwtSecretResolver(self::CONFIGURED, AppEnvironment::Local, true, self::DEV);

        self::assertSame(self::CONFIGURED, $resolver->resolve());
    }

    /**
     * The core of the hybrid model: production rejects even with the opt-in and a dev secret.
     */
    public function testProductionRejectsEvenWithOptInAndDevSecret(): void
    {
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Production, true, self::DEV);

        $this->expectException(JwtSecretException::class);
        $this->expectExceptionMessageMatches('/NENE2_LOCAL_JWT_SECRET/');

        $resolver->resolve();
    }

    public function testProductionMessageDoesNotSuggestOptIn(): void
    {
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Production, true, self::DEV);

        $this->expectException(JwtSecretException::class);
        $this->expectExceptionMessageMatches('/intentionally ignored in production/');

        $resolver->resolve();
    }

    /**
     * @return list<array{AppEnvironment}>
     */
    public static function nonProductionEnvironments(): array
    {
        return [
            [AppEnvironment::Local],
            [AppEnvironment::Test],
        ];
    }

    #[DataProvider('nonProductionEnvironments')]
    public function testDevSecretUsedWhenUnsetAndOptInAndInjected(AppEnvironment $environment): void
    {
        $resolver = new GuardedJwtSecretResolver('', $environment, true, self::DEV);

        self::assertSame(self::DEV, $resolver->resolve());
    }

    public function testThrowsWhenUnsetAndNoOptIn(): void
    {
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Local, false, self::DEV);

        $this->expectException(JwtSecretException::class);
        $this->expectExceptionMessageMatches('/NENE2_LOCAL_JWT_SECRET.*NENE2_ALLOW_DEV_SECRET/s');

        $resolver->resolve();
    }

    public function testThrowsWhenOptInButDevSecretIsNull(): void
    {
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Local, true, null);

        $this->expectException(JwtSecretException::class);

        $resolver->resolve();
    }

    public function testThrowsWhenOptInButDevSecretIsEmpty(): void
    {
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Local, true, '');

        $this->expectException(JwtSecretException::class);

        $resolver->resolve();
    }

    public function testEmptyConfiguredSecretIsTreatedAsUnset(): void
    {
        // Empty configured secret + no opt-in path => fail closed rather than sign with '' .
        $resolver = new GuardedJwtSecretResolver('', AppEnvironment::Local, false, null);

        $this->expectException(JwtSecretException::class);

        $resolver->resolve();
    }

    public function testCustomEnvNamesAppearInMessage(): void
    {
        $resolver = new GuardedJwtSecretResolver(
            '',
            AppEnvironment::Local,
            false,
            self::DEV,
            'NENE_SERVE_JWT_SECRET',
            'NENE_SERVE_ALLOW_DEV_SECRET',
        );

        $this->expectException(JwtSecretException::class);
        $this->expectExceptionMessageMatches('/NENE_SERVE_JWT_SECRET.*NENE_SERVE_ALLOW_DEV_SECRET/s');

        $resolver->resolve();
    }

    public function testFromConfigResolvesConfiguredSecret(): void
    {
        $config = $this->config(localJwtSecret: self::CONFIGURED, allowDevSecret: false);

        self::assertSame(self::CONFIGURED, GuardedJwtSecretResolver::fromConfig($config, self::DEV));
    }

    public function testFromConfigUsesDevSecretWithOptIn(): void
    {
        $config = $this->config(localJwtSecret: null, allowDevSecret: true, environment: AppEnvironment::Local);

        self::assertSame(self::DEV, GuardedJwtSecretResolver::fromConfig($config, self::DEV));
    }

    public function testFromConfigRejectsProductionWithOptIn(): void
    {
        $config = $this->config(localJwtSecret: null, allowDevSecret: true, environment: AppEnvironment::Production);

        $this->expectException(JwtSecretException::class);

        GuardedJwtSecretResolver::fromConfig($config, self::DEV);
    }

    public function testFromConfigRejectsWhenDevSecretNullAndNoConfiguredSecret(): void
    {
        $config = $this->config(localJwtSecret: null, allowDevSecret: true, environment: AppEnvironment::Local);

        $this->expectException(JwtSecretException::class);

        GuardedJwtSecretResolver::fromConfig($config, null);
    }

    private function config(
        ?string $localJwtSecret,
        bool $allowDevSecret,
        AppEnvironment $environment = AppEnvironment::Local,
    ): AppConfig {
        return new AppConfig(
            $environment,
            false,
            'NENE2 Test',
            new DatabaseConfig(null, 'test', 'sqlite', '', 0, ':memory:', '', '', ''),
            null,
            $localJwtSecret,
            'https://nene2.dev/problems/',
            $allowDevSecret,
        );
    }
}
