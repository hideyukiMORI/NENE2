# 操作指南：熔断器

> **FT 参考**：FT298（`NENE2-FT/circuitlog`）——熔断器模式：关闭/打开/半开三状态机，可配置失败阈值，基于超时的自动半开过渡，电路打开时返回 503 Service Unavailable，只读的 `isCallAllowed()` 检查，15 个测试 / 28 个断言全部通过。

熔断器模式可防止调用外部服务时的级联故障。它不会让缓慢或失败的调用堆积，而是在依赖项恢复之前立即断开电路并拒绝调用。

## 三种状态

```
关闭 ──（N次连续失败）──▶ 打开 ──（超时结束）──▶ 半开
  ▲                                                    │
  └──────────────────（成功）─────────────────────────┘
  半开 ──（失败）──▶ 打开
```

| 状态 | 行为 |
|------|------|
| **关闭（Closed）** | 正常——调用正常通过。每次错误时失败计数递增。 |
| **打开（Open）** | 调用立即被拒绝，返回 503。`failure_threshold` 次连续失败后打开 `timeout_seconds` 时间。 |
| **半开（Half-Open）** | 允许一次探测调用。成功 → 关闭（重置）。失败 → 再次打开。 |

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

电路名称通常是外部服务标识符（例如 `payment-gateway`、`email-svc`）。可以同时存在多个独立电路。

## 记录结果

```php
// 调用外部服务成功后：
$this->repo->recordSuccess($circuitName, $now);

// 调用失败后：
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` 决定状态转换：
- 如果 `failure_count + 1 >= failure_threshold` → 将状态设置为 `open`，计算 `open_until = now + timeout`。
- 如果仍低于阈值 → 递增 `failure_count`，保持 `closed`。
- 如果处于 `half_open` 状态 → 任何失败立即重新打开。

## 检查是否允许调用

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // 立即返回 503——不调用外部服务
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// 尝试调用...
```

在每个请求的 `isCallAllowed()` 检查之前调用 `maybeTransitionToHalfOpen()`。一旦 `open_until` 过期，这会将 `Open → Half-Open`，允许探测调用通过。

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## 半开时序

`Open → Half-Open` 过渡是惰性的：它在 `open_until` 过期后下次调用 `maybeTransitionToHalfOpen()` 时发生。这是有意为之——避免后台定时器，将状态变更与传入请求绑定。

## 失败阈值和超时调优

| 依赖类型 | 建议阈值 | 建议超时 |
|---------|---------|---------|
| 数据库（关键） | 3–5 | 10–30 秒 |
| 外部 API | 5–10 | 30–60 秒 |
| 非关键服务 | 10–20 | 60–120 秒 |

较高的阈值可以减少误报（临时抖动）。较长的超时给依赖项更多恢复时间，但会延长客户可见的降级时间。

## 每个服务多个电路

对不同的故障域使用不同的电路名称：

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

这样可以防止退款端点的故障阻塞充值请求。

## 电路打开时的响应

返回 `503 Service Unavailable` 并带有指向 `open_until` 的 `Retry-After` 头：

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

遵循 `503` 的客户端和负载均衡器可以在电路打开期间停止路由到此实例。

## 设计决策

**为什么使用数据库支持的状态而不是内存状态？** 内存状态在重启时丢失，且无法在 PHP-FPM worker 之间共享。数据库状态在所有 worker 之间保持一致，并能在重启后持久化，代价是每次受保护调用需要额外一次数据库查询。对于高吞吐量场景，可考虑使用 Redis 的原子递增操作。

**为什么使用惰性半开过渡？** 主动后台过渡需要调度器或守护进程。惰性过渡更简单，从调度器角度看是无状态的，对于大多数 web API 来说已足够——因为请求量足以确保检查及时运行。

**为什么 `failure_count` 在任何成功时重置？** 这是"连续失败"语义。另一种方式是"滑动窗口内的失败率"（例如，过去 60 秒内 >50% 失败）。滑动窗口对流量低但稳定的服务更准确；连续失败对要么正常要么完全宕机的服务更简单且足够。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 无 `UNIQUE(name)` 约束 | 并发创建为同一电路产生多行 |
| 打开电路时无超时 | 触发阈值后电路永久打开 |
| 无半开状态 | 电路直接从打开变为关闭；无探测验证 |
| 电路打开时返回 200 | 调用者认为调用成功；下游错误被隐藏 |
| 503 响应中无 `open_until` | 调用者立即重试（惊群效应）；需包含重试时间 |
| 将字符串 `"true"` 作为成功 | JSON 类型混淆；严格使用 `is_bool()` |
| 不先调用 `maybeTransitionToHalfOpen()` 就检查 `isCallAllowed()` | 打开的电路永远不会变为半开；永久卡住 |
| 仅使用内存状态 | worker 重启时状态丢失；PHP-FPM worker 之间无法共享 |
