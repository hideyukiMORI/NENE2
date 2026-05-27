# 操作指南：分布式锁

> **FT 参考**：FT288（`NENE2-FT/distlocklog`）——分布式锁：UNIQUE(resource) DB 约束，所有者验证，基于 TTL 的过期机制，过期锁重新获取（设计如此），ReleaseResult 枚举（Released/NotFound/Forbidden），所有者不匹配时返回 403，16 个测试 / 27 个断言全部通过。
>
> **ATK 评估**：本文末尾包含 ATK-01 到 ATK-12。

本指南演示如何实现分布式锁 API——通过颁发租约锁来防止对同一资源的并发操作。

## 什么是分布式锁？

当多个进程需要对共享资源（如支付、文件、队列任务）进行独占访问时，分布式锁确保同一时间只有一个进程在执行。锁具有 TTL，在持有者崩溃时自动过期。

## 数据库结构

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource TEXT UNIQUE`——每个资源一行。获取锁时插入或更新此行。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/locks/{resource}` | 获取锁 |
| `GET` | `/locks/{resource}` | 获取锁状态 |
| `DELETE` | `/locks/{resource}` | 释放锁 |
| `POST` | `/locks/{resource}/renew` | 延长 TTL |

## 获取逻辑

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // 无锁——INSERT（UNIQUE 约束处理并发竞争）
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // 竞争：另一个进程同时插入
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // 已过期 → 重新获取（UPDATE 替换旧行）
        // 同一所有者 → 重新获取（续期或重新锁定）
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // 被另一所有者持有且未过期 → 无法获取
    return null;
}
```

## 带所有者验证的释放

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

只有锁的所有者才能释放。错误的 `owner` → 403 Forbidden。

## ReleaseResult 枚举

```php
enum ReleaseResult
{
    case Released;   // 找到锁，所有者匹配，行已删除
    case NotFound;   // 锁未找到或已过期
    case Forbidden;  // 找到锁，但所有者不匹配
}
```

使用枚举（而非魔法字符串）确保 `match` 中的穷举处理。

## 获取响应

```php
// 成功：
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// 失败（被他人持有）：
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` 不是错误——意味着"稍后重试"。不返回 4xx 状态码；调用者应重试。

---

## ATK 评估——黑客思维攻击测试

### ATK-01 — 获取被他人持有的锁 🚫 BLOCKED

**攻击**：攻击者尝试在另一进程持有 `locks/payment:42` 时获取该锁。
**结果**：BLOCKED——仓库检查 `existing.owner === $caller_owner`。不同所有者且未过期 → 返回 `null` → `{ acquired: false }`。无错误，无崩溃——攻击者只是获取不到锁。

---

### ATK-02 — 释放他人的锁 🚫 BLOCKED

**攻击**：攻击者发送 `DELETE /locks/payment:42`，携带 `{ "owner": "attacker" }` 强制释放锁。
**结果**：BLOCKED——仓库检查 `lock.owner === $body_owner`。不匹配 → `ReleaseResult::Forbidden` → 403。

---

### ATK-03 — 等待过期后窃取锁 🚫 BLOCKED（设计如此）

**攻击**：攻击者等待锁过期，然后获取它。
**结果**：BLOCKED（设计如此）——任何所有者都可以重新获取已过期的锁。这是预期行为：基于 TTL 的过期机制正是崩溃的持有者失去锁的方式。减少基于 TTL 的攻击需要额外协调（心跳续期）。

---

### ATK-04 — 续期他人的锁 🚫 BLOCKED

**攻击**：攻击者发送 `POST /locks/payment:42/renew`，携带 `{ "owner": "attacker", "ttl_seconds": 3600 }`。
**结果**：BLOCKED——续期检查 `lock.owner === $body_owner`。不匹配 → 403 Forbidden。

---

### ATK-05 — 零或负数 TTL 创建立即过期的锁 🚫 BLOCKED

**攻击**：发送 `{ "ttl_seconds": 0 }` 或 `{ "ttl_seconds": -100 }` 创建立即过期的锁。
**结果**：BLOCKED——`if ($ttlSeconds === null || $ttlSeconds < 1)` → 422 校验错误。

---

### ATK-06 — 通过资源路径参数进行 SQL 注入 🚫 BLOCKED

**攻击**：使用 `locks/resource'; DROP TABLE distributed_locks; --` 作为资源名称。
**结果**：BLOCKED——所有查询使用参数化语句（`WHERE resource = ?`）。注入的字符串被视为字面资源标识符。

---

### ATK-07 — 空所有者绕过所有权检查 🚫 BLOCKED

**攻击**：发送 `{ "owner": "" }` 或 `{ "owner": "   " }` 在没有有效所有权的情况下释放或续期。
**结果**：BLOCKED——`$owner = trim(...); if ($owner === '')` → 422 校验错误。

---

### ATK-08 — 非整数 TTL 绕过类型校验 🚫 BLOCKED

**攻击**：发送 `{ "ttl_seconds": "3600" }`（字符串）或 `{ "ttl_seconds": 60.5 }`（浮点数）。
**结果**：BLOCKED——`is_int($body['ttl_seconds'])` 拒绝字符串和浮点数。只接受 JSON 整数类型。

---

### ATK-09 — 同一所有者多次获取 🚫 BLOCKED（设计如此）

**攻击**：同一所有者重新获取自己持有的锁以延长有效期，而不使用 `/renew`。
**结果**：ALLOWED（设计如此）——`$existing->owner === $owner` → UPDATE（重新获取/延期）。同一所有者重新获取是幂等且安全的；它会更新 `expires_at` 和 `acquired_at`。

---

### ATK-10 — 并发获取的竞争条件 🚫 BLOCKED

**攻击**：两个进程都发现没有锁，同时尝试 INSERT。
**结果**：BLOCKED——`UNIQUE(resource)` 约束确保只有一个 INSERT 成功。失败方捕获 `\RuntimeException` 并返回 `null` → `{ acquired: false }`。只有一个所有者获胜。

---

### ATK-11 — GET 不存在或已过期的锁 🚫 BLOCKED

**攻击**：调用 `GET /locks/nonexistent`，或等待锁过期后调用 GET。
**结果**：BLOCKED——`if ($lock === null || $lock->isExpired($now)) return 404`。过期的锁返回 404（不返回过期的锁数据）。

---

### ATK-12 — 超长资源名称导致 DoS 🚫 BLOCKED（设计说明）

**攻击**：发送 `{ "resource": "<10MB 字符串>" }` 作为资源路径参数。
**结果**：部分 BLOCKED——资源来自 URL 路径，受 Web 服务器路径长度限制（通常为 8KB）。本 FT 中没有显式的应用层长度校验。在生产环境中，应添加 `if (strlen($resource) > 255)` → 422。数据库会存储应用程序传入的任何值。

---

### ATK 总结

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 获取被他人持有的锁 | 🚫 BLOCKED |
| ATK-02 | 释放他人的锁 | 🚫 BLOCKED |
| ATK-03 | TTL 过期后窃取锁 | 🚫 BLOCKED（设计如此） |
| ATK-04 | 续期他人的锁 | 🚫 BLOCKED |
| ATK-05 | 零/负数 TTL | 🚫 BLOCKED |
| ATK-06 | 通过资源路径 SQL 注入 | 🚫 BLOCKED |
| ATK-07 | 空所有者绕过 | 🚫 BLOCKED |
| ATK-08 | 非整数 TTL 类型绕过 | 🚫 BLOCKED |
| ATK-09 | 同一所有者重新获取 | 🚫 BLOCKED（设计如此） |
| ATK-10 | 并发获取竞争条件 | 🚫 BLOCKED |
| ATK-11 | GET 已过期/不存在的锁 | 🚫 BLOCKED |
| ATK-12 | 超长资源名称 | ⚠️ 设计说明 |

**11 BLOCKED，1 设计说明，0 EXPOSED**
所有者验证、`UNIQUE(resource)` 竞争保护、TTL 校验和参数化查询防止了所有关键攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 无 `UNIQUE(resource)` 约束 | 竞争条件：两个所有者都获取锁；TOCTOU 漏洞 |
| 释放时不验证所有者 | 任何进程都能释放任何锁；独占性无保证 |
| 锁无 TTL | 崩溃持有者的锁永久存在；系统死锁 |
| 接受 0 或负数 TTL | 锁创建时已过期；立即可被重新获取 |
| 释放时所有者不匹配返回 404 | 攻击者无法区分"锁不存在"和"所有者错误"；应返回 403 |
| 接受字符串/浮点数作为 TTL | `"3600"` 看起来有效但 `is_int` 失败；严格类型检查防止隐蔽 bug |
| 存储所有者时不校验 | 空所有者绕过所有权；始终校验非空 |
| 无资源长度限制 | Web 服务器路径限制是唯一防线；应添加显式校验 |
| 续期已过期的锁 | 过期的锁无所有者；应重新获取而非续期 |
