<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use Nene2\Demo\CountingDemoCapacityGuard;
use Nene2\Demo\DemoCapacityExceededException;
use Nene2\Demo\DemoConfig;
use Nene2\Demo\DemoThrottledException;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class CountingDemoCapacityGuardTest extends TestCase
{
    public function testCapacityBelowCeilingPasses(): void
    {
        $guard = $this->makeGuard(demoOrgCount: 199, maxOrgs: 200);

        $guard->assertHasCapacity();

        $this->addToAssertionCount(1);
    }

    public function testCapacityAtCeilingThrows(): void
    {
        $guard = $this->makeGuard(demoOrgCount: 200, maxOrgs: 200);

        $this->expectException(DemoCapacityExceededException::class);
        $this->expectExceptionMessage('200 of 200');

        $guard->assertHasCapacity();
    }

    public function testCapacityAboveCeilingThrows(): void
    {
        $guard = $this->makeGuard(demoOrgCount: 250, maxOrgs: 200);

        $this->expectException(DemoCapacityExceededException::class);

        $guard->assertHasCapacity();
    }

    public function testThrottleAllowsUpToTheLimitThenThrows(): void
    {
        $guard = $this->makeGuard(throttleLimit: 2);
        $request = $this->makeRequest('203.0.113.1');

        $guard->assertNotThrottled($request);
        $guard->assertNotThrottled($request);

        try {
            $guard->assertNotThrottled($request);
            self::fail('Expected DemoThrottledException.');
        } catch (DemoThrottledException $exception) {
            self::assertGreaterThanOrEqual(0, $exception->retryAfterSeconds);
            self::assertStringContainsString('Demo start limit of 2', $exception->getMessage());
        }
    }

    public function testThrottleBucketsAreKeyedPerIp(): void
    {
        $guard = $this->makeGuard(throttleLimit: 1);

        $guard->assertNotThrottled($this->makeRequest('203.0.113.1'));
        $guard->assertNotThrottled($this->makeRequest('203.0.113.2'));

        $this->expectException(DemoThrottledException::class);

        $guard->assertNotThrottled($this->makeRequest('203.0.113.1'));
    }

    public function testCustomKeyExtractorOverridesTheIpKey(): void
    {
        $guard = new CountingDemoCapacityGuard(
            static fn (): int => 0,
            new DemoConfig(),
            new InMemoryRateLimitStorage(),
            throttleLimit: 1,
            keyExtractor: static fn (ServerRequestInterface $r): string => 'fixed-bucket',
        );

        $guard->assertNotThrottled($this->makeRequest('203.0.113.1'));

        $this->expectException(DemoThrottledException::class);

        // Different IP, same extracted key — must share the bucket.
        $guard->assertNotThrottled($this->makeRequest('203.0.113.2'));
    }

    private function makeGuard(
        int $demoOrgCount = 0,
        int $maxOrgs = 200,
        int $throttleLimit = 10,
    ): CountingDemoCapacityGuard {
        return new CountingDemoCapacityGuard(
            static fn (): int => $demoOrgCount,
            new DemoConfig(maxOrgs: $maxOrgs),
            new InMemoryRateLimitStorage(),
            $throttleLimit,
        );
    }

    private function makeRequest(string $remoteAddr): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://example.test/demo/kensetsu', ['REMOTE_ADDR' => $remoteAddr]);
    }
}
