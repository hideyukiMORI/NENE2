<?php

declare(strict_types=1);

namespace Nene2\Config;

/**
 * Runtime environment derived from the `APP_ENV` environment variable.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
enum AppEnvironment: string
{
    case Local = 'local';
    case Test = 'test';
    case Production = 'production';

    public static function fromConfigValue(string $value): self
    {
        return self::tryFrom($value) ?? throw new ConfigException(
            sprintf('APP_ENV must be one of: %s.', implode(', ', self::values())),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $environment): string => $environment->value,
            self::cases(),
        );
    }
}
