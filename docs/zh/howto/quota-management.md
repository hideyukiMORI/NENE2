# 操作指南：配额管理 API

> **FT 参考**：FT236（`NENE2-FT/quotalog`）——配额管理 API
> **ATK**：FT236——破解者思维攻击测试（ATK-01 到 ATK-12）

演示配额管理 API：每个用户/资源对有可配置的速率策略（按小时或按天），使用量在以窗口开始时间为键的独立表中追踪，`consume` 端点在超出限制时强制执行 `429 Too Many Requests`。`check`（只读）和 `consume`（变更）是独立操作。

---

## 路由

| 方法 | 路径 | 说明 |
|--------|------------------------------------------|----------------------------------------------------|
| `PUT`  | `/quotas/{userId}/{resource}`            | 创建或更新配额策略 |
| `GET`  | `/quotas/{userId}`                       | 列出用户的所有配额策略 |
| `GET`  | `/quotas/{userId}/{resource}`            | 检查当前配额状态（只读） |
| `POST` | `/quotas/{userId}/{resource}/consume`    | 消耗一个单位（超出时返回 429） |
| `POST` | `/quotas/{userId}/{resource}/reset`      | 将当前窗口的使用量重置为零 |

---

## QuotaWindow：计算窗口开始时间

`QuotaWindow` 是带有 `windowStart()` 方法的支持枚举，将当前时间戳向下取整到窗口边界：

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` 向下取整到当前小时；`setTime(0, 0, 0)` 向下取整到 UTC 午夜。结果作为 `window_start` 键存储在使用量表中——同一窗口内的所有请求共享相同的 `window_start` 值。

---

## 双表设计：策略与使用量

```sql
-- 配额策略：每个窗口允许的最大值
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- 使用量追踪：每个窗口的实际计数
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

将策略与使用量分离的优点：
- 策略跨窗口持久化——无需每个周期重新创建。
- 使用量行按 `window_start` 自动分区。旧窗口在表中累积；后台作业可以清理。
- 策略上的 `UNIQUE(user_id, resource)` 防止重复配置。
- 使用量上的 `UNIQUE(user_id, resource, window_start)` 确保每个窗口只有一个计数器。

---

## check 与 consume

`check` 是只读的——计算剩余量而不进行任何变更：

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` 先检查限制，只有在允许时才递增：

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // 配额超出——返回 allowed=false 的状态，不递增
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

控制器将 `allowed=false` 映射为 `429 Too Many Requests`：

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` 对于配额耗尽在语义上是正确的。在生产环境中包含指向窗口重置时间的 `Retry-After` 头。

---

## 使用量递增：先 SELECT 再 INSERT/UPDATE

使用量递增是应用层的 upsert：

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` 是原子的 DB 层递增——应用代码中没有读-改-写。`(user_id, resource, window_start)` 上的 `UNIQUE` 约束防止两个并发首次使用插入之间的竞态条件。

---

## 通过 `PUT` 实现策略 upsert

`PUT /quotas/{userId}/{resource}` 是幂等的——创建或更新：

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

`is_int()` 严格检查拒绝 JSON 浮点数和字符串。`limitCount < 1` 要求至少为 1——零和负值被拒绝。

---

## ATK——破解者思维攻击测试（FT236）

### ATK-01 — 无认证 ⚠️ EXPOSED

**攻击**：无凭证即为任意用户创建配额策略或消耗配额。

```bash
curl -s -X PUT http://localhost:8200/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**结果**：`200 OK`——无需令牌。任何人都可以设置或耗尽任意用户的配额。

**裁定**：**EXPOSED**（FT236 演示版本按设计如此）。添加认证；将策略管理限制在管理员角色后，消耗操作限制在所有者的令牌后。

---

### ATK-02 — 通过 `{resource}` 路径参数的 SQL 注入 🚫 BLOCKED

**攻击**：在资源名称中嵌入 SQL 元字符。

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**结果**：资源字符串直接作为参数化的 `?` 值传递给所有查询——没有字符串插值。注入的 SQL 作为字面字符串存储/比较，不被执行。

**裁定**：🚫 **BLOCKED**——参数化查询防止通过路径参数的注入。

---

### ATK-03 — 负数或零 `limit_count` 🚫 BLOCKED

**攻击**：将限制设为 0 或 -1 来禁用其他用户的访问。

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**结果**：`$limitCount < 1` 检查触发 → `422 Unprocessable Entity`，带有 `limit_count` 的结构化错误。

**裁定**：🚫 **BLOCKED**——在应用层强制执行最小 `limit_count` 为 1。

---

### ATK-04 — 无效的 `window` 值 🚫 BLOCKED

**攻击**：发送不支持的窗口字符串。

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**结果**：`QuotaWindow::tryFrom('weekly')` 返回 `null` → `422`，带有 `window` 的结构化错误。

**裁定**：🚫 **BLOCKED**——支持枚举的 `tryFrom()` 拒绝未知窗口值。

---

### ATK-05 — 无策略时消耗 🚫 BLOCKED

**攻击**：对没有配置策略的用户/资源调用 `POST .../consume`。

```bash
curl -s -X POST http://localhost:8200/quotas/user-ghost/api-calls/consume
```

**结果**：`findPolicy()` 返回 `null` → `404 Not Found`，带有 Problem Details 响应。

**裁定**：🚫 **BLOCKED**——无策略则无法消耗。调用方必须在消耗之前配置策略。

---

### ATK-06 — 浮点 `limit_count` 🚫 BLOCKED

**攻击**：发送浮点数而非整数。

```json
{"window": "daily", "limit_count": 9.9}
```

**结果**：PHP 中 `is_int(9.9)` = `false`——从 JSON 解码的浮点值（`float` 类型）未通过检查。`$limitCount` 默认为 `-1` → `< 1` 守护触发 → `422`。

**裁定**：🚫 **BLOCKED**——`is_int()` 严格类型检查拒绝 JSON 浮点数。

---

### ATK-07 — 极大的 `limit_count` ⚠️ EXPOSED

**攻击**：将 limit_count 设置为 `PHP_INT_MAX` 或 `9999999999`。

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**结果**：`is_int()` 通过（PHP 将其表示为 `int`）；`< 1` 检查通过。值被存储并用于比较，没有问题。不存在上限。

**裁定**：⚠️ **EXPOSED**——未强制执行最大 `limit_count`。非常大的限制实际上等同于"无限制"。添加：
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — 并发消耗时的竞态条件 ⚠️ EXPOSED

**攻击**：当 `usage == limit - 1` 时同时发送两个 `POST .../consume` 请求。

**结果**：两个请求在任何递增运行之前都读取 `usage = limit - 1`。两者都看到 `usage < limitCount` → 两者都调用 `incrementUsage()`。两者都成功——usage 最终为 `limit + 1`，两个响应都返回 `allowed: true`。

**裁定**：⚠️ **EXPOSED**——先检查后递增的模式不是原子的。用事务修复：
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- 检查 < limit
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
或在 PostgreSQL 上使用 `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage`。

---

### ATK-09 — 未知或任意 `{resource}` 名称 🚫 BLOCKED

**攻击**：使用从未打算使用的资源名称。

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**结果**：路径遍历（`../`）在路由之前被 URL 解码；路由器将其视为多段路径，不匹配 `{resource}` 路由。特殊字符通过参数化查询作为字面字符串存储（见 ATK-02）。

**裁定**：🚫 **BLOCKED**——路由器拒绝路径遍历，SQL 是安全的。如果资源名称应限制为已知值，考虑添加资源名称白名单或格式检查。

---

### ATK-10 — 重置其他用户的配额 ⚠️ EXPOSED

**攻击**：重置不同用户的配额计数器以绕过其限流。

```bash
curl -s -X POST http://localhost:8200/quotas/target-user/api-calls/reset
```

**结果**：`200 OK`——无所有权检查。任何调用方都可以重置任意用户的配额使用量，立即恢复其访问。

**裁定**：⚠️ **EXPOSED**——与 ATK-01 根源相同。将 `reset` 限制在管理员角色后。

---

### ATK-11 — `{userId}` 和 `{resource}` 无长度限制 ⚠️ EXPOSED

**攻击**：发送极长的路径段值。

```
PUT /quotas/<10000 字符>/<5000 字符>
```

**结果**：长字符串被接受并存储在无限制的 `TEXT` 列中。非常长的键会降低索引性能。

**裁定**：⚠️ **EXPOSED**——添加长度限制：
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — 通过时钟漂移操纵 `window_start` 🚫 BLOCKED

**攻击**：如果调用方可以影响 `$now`，可以移动窗口开始时间来人为延长或重启窗口。

**结果**：`$now` 在控制器内通过 `new \DateTimeImmutable()` 计算——不是用户提供的。调用方无法影响窗口计算。

**裁定**：🚫 **BLOCKED**——服务器时钟是唯一的时间源。对于有多个节点的分布式系统，确保所有节点使用 UTC 并同步 NTP。

---

## ATK 汇总

| # | 攻击向量 | 裁定 |
|---|---------------|---------|
| ATK-01 | 无认证 | ⚠️ EXPOSED |
| ATK-02 | 通过资源路径参数的 SQL 注入 | 🚫 BLOCKED |
| ATK-03 | 负数/零 limit_count | 🚫 BLOCKED |
| ATK-04 | 无效 window 值 | 🚫 BLOCKED |
| ATK-05 | 无策略时消耗 | 🚫 BLOCKED |
| ATK-06 | 浮点 limit_count | 🚫 BLOCKED |
| ATK-07 | 极大的 limit_count | ⚠️ EXPOSED |
| ATK-08 | 并发消耗竞态条件 | ⚠️ EXPOSED |
| ATK-09 | 任意资源名称 | 🚫 BLOCKED |
| ATK-10 | 重置其他用户配额 | ⚠️ EXPOSED |
| ATK-11 | userId/resource 无长度限制 | ⚠️ EXPOSED |
| ATK-12 | 窗口开始时间操纵 | 🚫 BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-01 / ATK-10** — 添加认证与授权
2. **ATK-08** — 将 consume 包装在事务中（原子性先检查后递增）
3. **ATK-07** — 添加 `limit_count` 的上限
4. **ATK-11** — 添加路径参数值的长度限制

---

## 相关指南

- [`rate-limiting.md`](rate-limiting.md) — 中间件级别的限流
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — 滑动窗口计数器
- [`api-usage-metering.md`](api-usage-metering.md) — 按 API 密钥的使用量追踪
- [`credit-ledger.md`](credit-ledger.md) — 类配额系统的信用/扣款模型
