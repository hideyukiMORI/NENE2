<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nene2\Http\ClockInterface;
use Nene2\Http\UtcClock;
use Nene2\Middleware\RateLimitStorageInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Framework-default {@see DemoCapacityGuardInterface}: an injected counter for the
 * instance-wide ceiling, and a {@see RateLimitStorageInterface} fixed window for
 * the per-client throttle.
 *
 * The counter is a closure (`fn (): int` returning the number of currently
 * existing demo orgs, e.g. `SELECT COUNT(*) FROM organizations WHERE slug LIKE
 * 'demo-%'`) so the framework carries no knowledge of the product's tenant
 * schema. The throttle reuses the same storage contract as
 * {@see \Nene2\Middleware\ThrottleMiddleware}, including its default REMOTE_ADDR
 * key and its reverse-proxy caveat: behind a proxy, inject a `$keyExtractor`
 * that reads your trusted forwarded-IP header, or every client shares one bucket.
 *
 * **Production requirement**: back the throttle with shared storage (Redis,
 * Memcached, or a database) — the bundled
 * {@see \Nene2\Middleware\InMemoryRateLimitStorage} does not share state across
 * PHP-FPM worker processes.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class CountingDemoCapacityGuard implements DemoCapacityGuardInterface
{
    /** @var \Closure(): int */
    private \Closure $demoOrgCount;

    /** @var \Closure(ServerRequestInterface): string */
    private \Closure $keyExtractor;

    /**
     * @param \Closure(): int $demoOrgCount Returns the number of currently existing demo orgs.
     * @param \Closure(ServerRequestInterface): string|null $keyExtractor
     *   Extracts the throttle key from the request. Defaults to REMOTE_ADDR.
     */
    public function __construct(
        \Closure $demoOrgCount,
        private readonly DemoConfig $config,
        private readonly RateLimitStorageInterface $throttleStorage,
        private readonly int $throttleLimit = 10,
        private readonly int $throttleWindowSeconds = 3600,
        ?\Closure $keyExtractor = null,
        private readonly ClockInterface $clock = new UtcClock(),
    ) {
        $this->demoOrgCount = $demoOrgCount;
        $this->keyExtractor = $keyExtractor ?? static function (ServerRequestInterface $request): string {
            $params = $request->getServerParams();
            $address = $params['REMOTE_ADDR'] ?? 'unknown';

            return 'demo-start:ip:' . (is_string($address) ? $address : 'unknown');
        };
    }

    public function assertHasCapacity(): void
    {
        $count = ($this->demoOrgCount)();

        if ($count >= $this->config->maxOrgs) {
            throw new DemoCapacityExceededException(sprintf(
                'Demo organization ceiling reached (%d of %d).',
                $count,
                $this->config->maxOrgs,
            ));
        }
    }

    public function assertNotThrottled(ServerRequestInterface $request): void
    {
        $key = ($this->keyExtractor)($request);
        $result = $this->throttleStorage->hit($key, $this->throttleWindowSeconds);

        if ($result['count'] > $this->throttleLimit) {
            $retryAfter = max(0, $result['reset_at'] - $this->clock->now()->getTimestamp());

            throw new DemoThrottledException(
                sprintf(
                    'Demo start limit of %d per %d seconds exceeded. Try again in %d seconds.',
                    $this->throttleLimit,
                    $this->throttleWindowSeconds,
                    $retryAfter,
                ),
                $retryAfter,
            );
        }
    }
}
