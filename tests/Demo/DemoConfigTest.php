<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use Nene2\Config\ConfigException;
use Nene2\Demo\DemoConfig;
use PHPUnit\Framework\TestCase;

final class DemoConfigTest extends TestCase
{
    public function testDefaultsMatchTheInvoiceProductionValues(): void
    {
        $config = new DemoConfig();

        self::assertFalse($config->demoMode);
        self::assertSame('demo-', $config->slugPrefix);
        self::assertSame(3, $config->ttlHours);
        self::assertSame(200, $config->maxOrgs);
        self::assertSame(5, $config->slugAttempts);
    }

    public function testEmptySlugPrefixIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_SLUG_PREFIX');

        new DemoConfig(slugPrefix: '  ');
    }

    public function testNonPositiveTtlIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_TTL_HOURS');

        new DemoConfig(ttlHours: 0);
    }

    public function testNonPositiveMaxOrgsIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_MAX_ORGS');

        new DemoConfig(maxOrgs: 0);
    }

    public function testNonPositiveSlugAttemptsIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEMO_SLUG_ATTEMPTS');

        new DemoConfig(slugAttempts: 0);
    }
}
