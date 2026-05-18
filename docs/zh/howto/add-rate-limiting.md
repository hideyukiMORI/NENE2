# 添加速率限制

本指南演示如何使用 `ThrottleMiddleware` 和 `RateLimitStorageInterface` 为您的 NENE2 应用添加请求速率限制保护。

**前提条件**：您已有一个可运行的 NENE2 应用。如果还没有，请先阅读[教程](../tutorial/first-api.md)。

---

## 快速开始

将 `ThrottleMiddleware` 添加到 `RuntimeApplicationFactory`。内置的 `InMemoryRateLimitStorage`
适用于本地开发和单进程部署。

```php
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17    = new Psr17Factory();
$problems = new ProblemDetailsResponseFactory($psr17, $psr17);
$storage  = new InMemoryRateLimitStorage();

$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          60,   // 每个时间窗口允许的请求数
    windowSeconds:  60,   // 时间窗口长度（秒）
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` 位于中间件栈的第 8 位——在认证之后，因此您可以选择按已认证用户进行限制
（参见下方的自定义键提取器）。

---

## 工作原理

对于每个请求，中间件将：

1. 为客户端计算一个键（默认：`REMOTE_ADDR`）。
2. 在存储后端递增计数器。
3. 如果计数器**不超过**限制——放行请求并添加速率限制响应头。
4. 如果计数器**超过**限制——返回带有 Problem Details 的 `429 Too Many Requests`。

### 响应头

每个响应（包括 429）都包含以下响应头：

| 响应头 | 值 |
|---|---|
| `X-RateLimit-Limit` | 每个时间窗口配置的限制数 |
| `X-RateLimit-Remaining` | 当前时间窗口内剩余的请求数 |
| `X-RateLimit-Reset` | 时间窗口重置时的 Unix 时间戳 |
| `Retry-After` | 距时间窗口重置的秒数（仅 429） |

### 429 响应体

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit exceeded. Try again in 42 seconds.",
  "instance": "/examples/notes"
}
```

---

## 自定义键提取器

默认情况下，键为客户端 IP 地址（`REMOTE_ADDR`）。传入一个 `Closure` 可按已认证用户、API 键
或任何其他维度进行限制。

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          1000,
    windowSeconds:  3600,
    keyExtractor:   static function (ServerRequestInterface $request): string {
        return $request->getAttribute('user_id', 'anonymous');
    },
);
```

---

## 替换存储后端

`InMemoryRateLimitStorage` 将计数器存储在 PHP 进程内存中。在 FPM 部署中每次请求后都会重置，
且**不在进程间共享**。在生产环境中，您需要使用 Redis 等共享存储。

实现 `RateLimitStorageInterface`：

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    /** @return array{count: int, reset_at: int} */
    public function hit(string $key, int $windowSeconds): array
    {
        $redisKey = "rate:{$key}";
        $count    = (int) $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }
        $ttl     = max(0, (int) $this->redis->ttl($redisKey));
        $resetAt = time() + $ttl;
        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

---

## 设计决策

参见 [ADR 0010](/adr/0010-rate-limiting) 了解以下决策的依据：
- 固定时间窗口算法的选择
- 基于 IP 的默认键
- 响应头约定（`X-RateLimit-*`、`Retry-After`）
- `RateLimitStorageInterface` 抽象边界

---

## 下一步

查看 [Problem Details 类型](../reference/problem-details-types.md) 了解完整的 `429` 错误结构，
或查看[添加健康检查](./add-health-check.md)了解配套的可观测性功能。
