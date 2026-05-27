# 操作指南：固定窗口限流器

> **FT 参考**：FT251（`NENE2-FT/ratelimitlog`）——使用 SQLite upsert 实现固定窗口限流

演示基于 SQLite 的固定窗口限流器。每个 `(key, window_start)` 对累积请求计数。当计数超过配置的限制时，请求会被拒绝，返回 `429 Too Many Requests` 和 `Retry-After` 响应头。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/ping` | 受限端点（读取 `X-Client-Key`） |
| `GET` | `/status` | 读取某个 key 的当前计数器（`?key=`） |

---

## 数据库结构：复合主键作为限流计数器存储

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- ISO 8601 时间戳，截断到窗口边界
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` 唯一标识每个 `(客户端, 窗口)` 对的计数器。
索引使 upsert 查找更快。另有一个 `api_calls` 日志表记录每次成功请求，用于审计目的。

---

## Upsert 模式：`INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

某个 `(key, windowStart)` 对的第一个请求插入 `count = 1`。同一窗口内的后续请求通过 `DO UPDATE SET count = count + 1` 原子性递增。
不需要 `INSERT` 前的 `SELECT`——在 SQLite 中 upsert 是原子性的。

upsert 之后读取计数器以检测是否超出限制：

```php
$row   = $this->executor->fetchOne(
    'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
    [$key, $windowStart],
);
$count = (int) ($row['count'] ?? 0);

if ($count > $this->limit) {
    $retryAfter = (int) (strtotime($windowEnd) - strtotime($now));
    throw new RateLimitExceededException($key, $this->limit, $this->windowSeconds, max(0, $retryAfter));
}
```

检查在递增**之后**触发。这意味着第（limit+1）个请求在被拒绝之前已被计数——超出限制的请求计数器会达到 `limit + 1`。
这是有意为之：计数器准确反映总尝试次数，而非允许的次数。

---

## 窗口截断：固定边界

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` 是当前窗口内的偏移量。减去它即得到窗口起始时间戳。以 `2026-01-01T00:00:45Z` 的 60 秒窗口为例：

```
ts     = 1751328045  (Unix 时间戳)
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

从 `:00` 到 `:59` 的所有请求共享相同的 `window_start = 2026-01-01T00:00:00Z`。
到 `:60` 时新窗口开始，计数器重置。

**固定窗口 vs 滑动窗口权衡**：

| 属性 | 固定窗口 | 滑动窗口 |
|------|----------|----------|
| 实现 | 每个请求单次 upsert | 跨桶的多次读写 |
| 内存 | 每（key, window）1 行 | 每 key N 行（子桶） |
| 边界突发 | 有——窗口边界可能出现 2 倍限制 | 无——跨时间平滑限制 |
| 常见用途 | 简单 API、内部工具 | 面向公众的 API、严格公平性 |

---

## `429 Too Many Requests` 与 `Retry-After`

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,
    ) {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds}s exceeded for key '{$key}'.");
    }
}
```

异常携带 `retryAfter`（当前窗口到期前的秒数）。处理程序将其映射为带 `Retry-After` 响应头的 `429` Problem Details 响应：

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);

    $response = $this->probs->create(
        request: $request,
        type: 'rate-limit-exceeded',
        title: 'Too Many Requests',
        status: 429,
        detail: $exception->getMessage(),
    );

    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`Retry-After` 是客户端重试前应等待的秒数。
它计算为 `windowEnd - now`，限定为 `>= 0`。

---

## 通过 `X-Client-Key` 请求头进行客户端标识

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

每个客户端通过其 `X-Client-Key` 请求头标识。缺少该头时回退到 `'127.0.0.1'`——所有未认证客户端共享一个计数器。在生产环境中：

- 使用从已认证会话中提取的已验证用户 ID 或 API 密钥——而非客户端可以伪造的请求头。
- 使用 `$_SERVER['REMOTE_ADDR']`（代理去除后）进行基于 IP 的限制。
- 永远不要直接使用 `X-Forwarded-For`——客户端可以伪造它来绕过限制。

---

## 只读状态端点

```php
public function currentCount(string $key, string $now): int
{
    $windowStart = $this->truncateToWindow($now);
    $row = $this->executor->fetchOne(
        'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
        [$key, $windowStart],
    );

    return (int) ($row['count'] ?? 0);
}
```

`GET /status?key=xxx` 返回当前计数器而不递增它。
用于监控面板或客户端退避逻辑。

---

## 窗口过期清理

```php
public function pruneExpired(string $now): int
{
    $cutoff = $this->subtractSeconds($now, $this->windowSeconds * 2);

    return $this->executor->execute(
        'DELETE FROM rate_limit_windows WHERE window_start < ?',
        [$cutoff],
    );
}
```

随着时间推移，旧窗口会不断积累。`pruneExpired()` 删除两个窗口时长之前的行（保留当前窗口 + 上一个窗口；删除更旧的）。

从后台任务或每次请求后（采样方式——例如 `rand(0, 99) === 0` 以约 1% 的概率运行）调用 `pruneExpired()`：

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## 配置注入

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` 和 `windowSeconds` 在构造时注入。不同端点可以使用具有不同配置的限流器实例：

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## 相关操作指南

- [`rate-limiting.md`](rate-limiting.md) — 用于按路由限流的 `ThrottleMiddleware`
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — 使用子桶的滑动窗口（ratelog FT200）
- [`add-rate-limiting.md`](add-rate-limiting.md) — 为现有路由添加限流
- [`quota-management.md`](quota-management.md) — 更长时间范围的配额（每日、每月）
- [`api-usage-metering.md`](api-usage-metering.md) — 带配额检查的按用户使用量追踪
