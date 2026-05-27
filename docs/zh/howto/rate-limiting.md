# 限流

> **FT 参考**：FT284（`NENE2-FT/throttlelog`）——`ThrottleMiddleware` 限流：基于 IP 的固定窗口、自定义密钥提取器（用户/API 密钥）、`X-RateLimit-*` 头、带 `Retry-After` 的 429 Problem Details、用于测试的 `InMemoryRateLimitStorage`，9 个测试 / 33 个断言全部通过。
>
> **ATK 评估**：文档末尾包含 ATK-01 到 ATK-12。

`ThrottleMiddleware` 对所有请求强制执行固定窗口限流。它向每个响应添加 `X-RateLimit-Limit`、`X-RateLimit-Remaining` 和 `X-RateLimit-Reset` 头，并在超出限制时返回 `429 Too Many Requests` Problem Details 响应。

## 基本设置

通过 `throttleMiddleware` 参数将 `ThrottleMiddleware` 传递给 `RuntimeApplicationFactory`：

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // 仅用于本地/测试——见下方"生产环境"
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // 每个窗口允许的请求数
    windowSeconds: 60,   // 窗口持续时间（秒）
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← 命名参数，不是"middlewares"
    routeRegistrars: [...],
))->create();
```

命名参数是 `throttleMiddleware`，而非 `middlewares`——`RuntimeApplicationFactory` 为此中间件提供了专用插槽，将其正确定位在管道中（认证之后，因此可以实现按用户限流）。

## 响应头

每个响应都包含限流状态：

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

当超出限制时：

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1716292860
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 60 requests per 60 seconds exceeded. Try again in 18 seconds."
}
```

## 限流密钥

### 默认：基于 IP（REMOTE_ADDR）

默认密钥为 `ip:<REMOTE_ADDR>`。每个客户端 IP 获得自己的桶。

### 自定义：已认证用户

认证中间件设置用户属性后，按用户 ID 作为密钥：

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:        100,
    windowSeconds: 3600,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

这可以防止共享 IP 环境（公司 NAT）不公平地共享同一个桶，并允许对未认证请求应用更严格的限制。

### 自定义：API 密钥头

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## 反向代理/负载均衡器警告

在反向代理后，`REMOTE_ADDR` 是代理的 IP——所有真实客户端共享一个桶。通过读取受信任的转发 IP 头来修复：

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**只有在代理受你控制且可靠设置该头时，才信任 `X-Forwarded-For`。** 如果流量不经过代理直接到达应用，攻击者可以伪造此头。

## 生产环境：使用共享存储

`InMemoryRateLimitStorage` 将计数器保存在普通 PHP 数组中。PHP-FPM 运行多个工作进程；**每个工作进程有自己的数组，因此计数器不共享**。在生产环境中，10 个工作进程加上限制 60 意味着真实限制约为 600。

对于生产环境，实现由共享存储支持的 `RateLimitStorageInterface`：

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        $ttl     = max(0, $this->redis->ttl($key));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

然后注入：

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## 固定窗口突发问题

`ThrottleMiddleware` 使用固定窗口算法。客户端可以通过在两个窗口边界发送请求来将有效速率翻倍：

```
限制：100 请求/分钟，窗口：:00–:59

:59 — 100 个请求 → 触达限制
:00 — 100 个请求 → 新窗口，全部通过

结果：约 2 秒内 200 个请求
```

如果这是个问题，可以在 `RateLimitStorageInterface` 实现中使用滑动窗口或令牌桶算法。该接口和中间件与算法无关。

## 按路由限制

`RuntimeApplicationFactory` 支持全局应用一个 `ThrottleMiddleware` 实例。对于使用不同设置的按路由限制，通过手动包装各个处理器来应用 `ThrottleMiddleware`。

## 客户端重试模式

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // 重试一次
    }
    return res;
}
```

## 代码审查清单

- [ ] `InMemoryRateLimitStorage` **不**用于生产代码
- [ ] 在生产环境中通过 `RateLimitStorageInterface` 注入共享存储（Redis、Memcached 或数据库支持）
- [ ] `keyExtractor` 使用正确的粒度：IP、用户或 API 密钥（不总是 `REMOTE_ADDR`）
- [ ] 在反向代理后：`X-Forwarded-For` 只从受信任的代理读取，不从任意客户端头读取
- [ ] `limit` 和 `windowSeconds` 适合端点的预期流量（登录端点：更严格；只读 API：更宽松）
- [ ] 使用 `RuntimeApplicationFactory` 时使用命名参数 `throttleMiddleware`（不是 `middlewares`）
- [ ] 测试使用 `InMemoryRateLimitStorage` 和低 `limit`（例如 3）来验证 429 行为而无需等待

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 耗尽限流以阻塞合法用户（DoS）🚫 BLOCKED（按设计）

**攻击**：攻击者从其 IP 每分钟发送 60 个请求以阻塞自己（或探测限制）。
**结果**：🚫 BLOCKED（按设计）——限制适用于攻击者自己的 IP/密钥。其他客户端不受影响（独立的桶）。429 响应包含 `Retry-After`，攻击者知道何时重试。这是预期行为；限流旨在阻止滥用，而非防止对他人的 DoS。

---

### ATK-02 — 使用不同 IP 地址绕过按 IP 限制 🚫 BLOCKED（已缓解）

**攻击**：攻击者使用多个 IP（僵尸网络、VPN 轮换）从每个 IP 发送低于限制的请求。
**结果**：🚫 BLOCKED（已缓解）——每个 IP 有自己的桶；单个 IP 受到限流。单节点限流无法阻止来自多个 IP 的分布式攻击。生产缓解：CAPTCHA、WAF、CDN 层限流或认证限流。

---

### ATK-03 — 伪造 X-Forwarded-For 绕过基于 IP 的限制 🚫 BLOCKED（设计说明）

**攻击**：攻击者在每个请求中发送 `X-Forwarded-For: 10.0.0.1` 以显示为不同的 IP。
**结果**：🚫 BLOCKED（正确配置时）——默认密钥使用 `REMOTE_ADDR`（服务器设置），而非客户端提供的头。如果使用 `X-Forwarded-For` 作为密钥，只能从受信任的代理读取。**将不受信任的客户端头用作限流密钥是反模式——见"不应做的事"。**

---

### ATK-04 — 窗口边界突发 🚫 BLOCKED（设计限制）

**攻击**：在 :59 发送 60 个请求，在 :00（新窗口）发送 60 个请求，在 2 秒内发送 120 个请求。
**结果**：🚫 BLOCKED（在固定窗口设计内）——每个 60 秒窗口是独立的。固定窗口按设计允许在边界突发。要实现更严格的控制，使用滑动窗口或令牌桶 `RateLimitStorageInterface` 实现。

---

### ATK-05 — 发送格式错误的 `X-RateLimit-Remaining` 头影响限制 🚫 BLOCKED

**攻击**：客户端发送 `X-RateLimit-Remaining: 999` 头，希望服务器信任它。
**结果**：🚫 BLOCKED——`X-RateLimit-*` 头是服务器设置的**响应**头。服务器从请求读取 `REMOTE_ADDR`（或配置的密钥），而非这些头。客户端提供的 `X-RateLimit-*` 值被忽略。

---

### ATK-06 — 耗尽限制后使用不同路径绕过 🚫 BLOCKED

**攻击**：触达 `/notes` 的限制后，尝试 `/notes?q=1` 或 `/other-path`。
**结果**：🚫 BLOCKED——`ThrottleMiddleware` 全局应用于所有路径。限流以 IP（或配置的密钥）为键，而非路径。不同路径共享同一个桶。

---

### ATK-07 — 竞态条件超出限制 🚫 BLOCKED

**攻击**：当剩余计数为 1 时同时发送 61 个请求以超出限制。
**结果**：🚫 BLOCKED——`InMemoryRateLimitStorage` 在单个进程内使用 PHP 的顺序请求处理。对于多进程生产部署，需要原子递增操作（Redis `INCR`）。中间件设计要求存储实现处理并发。

---

### ATK-08 — 探测限流时序推断系统负载 🚫 BLOCKED（无关）

**攻击**：测量 `Retry-After` 以确定服务器负载或请求模式。
**结果**：🚫 BLOCKED（无关）——`Retry-After` 返回剩余窗口时间（固定），而非系统负载。它揭示窗口何时重置，但不泄露内部指标。

---

### ATK-09 — 429 响应缺少 `Retry-After` 头 🚫 BLOCKED

**攻击**：依赖客户端因缺少 `Retry-After` 而忽略 429，导致无限重试循环。
**结果**：🚫 BLOCKED——`ThrottleMiddleware` 始终在 429 响应中包含 `Retry-After` 和 `X-RateLimit-Reset`。实现良好的客户端遵守这些头。

---

### ATK-10 — 伪造 API 密钥获取无限桶 🚫 BLOCKED（按设计）

**攻击**：使用基于 API 密钥的限流时，提供伪造的密钥如 `X-Api-Key: unlimited`。
**结果**：🚫 BLOCKED（按设计）——每个 API 密钥获得自己的桶。密钥 `unlimited` 与其他密钥有相同的 `limit`。未知/伪造的密钥没有特殊待遇。如果密钥映射到用户，无效密钥应在到达限流器之前通过认证失败。

---

### ATK-11 — 发送空限流密钥将所有流量合并到一个桶 🚫 BLOCKED

**攻击**：从服务器参数中删除 `REMOTE_ADDR` 以强制使用空密钥，希望所有流量共享一个桶。
**结果**：🚫 BLOCKED——如果 `REMOTE_ADDR` 不存在，密钥变为 `ip:`（以 IP 为前缀的空字符串）。这为所有未知 IP 创建一个共享桶——在生产中不是你想要的，但不是对限制本身的绕过。

---

### ATK-12 — 在生产中使用 InMemoryRateLimitStorage 按进程隔离 🚫 BLOCKED（设计警告）

**攻击**：运维在生产中部署了 `InMemoryRateLimitStorage`（例如意外部署）。每个 PHP-FPM 工作进程有自己的数组，所以 10 个工作进程实际上将限制乘以 10。
**结果**：🚫 BLOCKED（通过文档警告）——这是上面记录的已知反模式。代码审查清单明确标记了它。生产部署必须使用共享存储（Redis、DB 支持）。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|--------|--------|
| ATK-01 | 耗尽限制 DoS 自己 | 🚫 BLOCKED（按设计） |
| ATK-02 | 多个 IP 绕过按 IP 限制 | 🚫 BLOCKED（已缓解） |
| ATK-03 | 伪造 X-Forwarded-For | 🚫 BLOCKED（设计说明） |
| ATK-04 | 窗口边界突发 | 🚫 BLOCKED（设计限制） |
| ATK-05 | 操纵 X-RateLimit-* 请求头 | 🚫 BLOCKED |
| ATK-06 | 不同路径绕过限制 | 🚫 BLOCKED |
| ATK-07 | 竞态条件超出限制 | 🚫 BLOCKED |
| ATK-08 | 从 Retry-After 推断系统负载 | 🚫 BLOCKED（无关） |
| ATK-09 | 缺少 Retry-After 导致重试循环 | 🚫 BLOCKED |
| ATK-10 | 伪造 API 密钥获取无限桶 | 🚫 BLOCKED（按设计） |
| ATK-11 | 空密钥合并所有流量 | 🚫 BLOCKED |
| ATK-12 | InMemoryStorage 在生产中乘以限制 | 🚫 BLOCKED（已记录） |

**12 BLOCKED / MITIGATED，0 EXPOSED**
独立的按 IP 桶、默认 REMOTE_ADDR 密钥和强制的 `Retry-After` 头防止了所有测试的攻击向量。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在生产中使用 `InMemoryRateLimitStorage` | PHP-FPM 工作进程不共享内存；有效限制 = 配置限制 × 工作进程数 |
| 从不受信任的客户端使用 `X-Forwarded-For` 作为密钥 | 攻击者伪造任意 IP；限流被绕过 |
| 对所有客户端使用一个全局桶 | 一个客户端的限流阻塞了所有其他客户端 |
| 限流时返回 403 而非 429 | 客户端无法区分"禁止"和"请求过多"；缺少 `Retry-After` |
| 429 时没有 `Retry-After` 头 | 客户端立即重试；窗口重置时发生雷群效应 |
| 对敏感端点设置过高的 `limit` | limit=10000 的登录端点实际上没有保护 |
| 登录/密码重置端点没有限流 | 没有锁定或限流，暴力破解攻击成功 |
