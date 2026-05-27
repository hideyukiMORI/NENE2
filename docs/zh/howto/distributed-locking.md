# 分布式锁

分布式锁防止并发进程同时执行临界区代码。基于数据库的锁以吞吐量换取简单性——无需 Redis，且持有数据的同一数据库也持有锁。

## 核心概念

- **资源**：被锁定对象的名称（例如 `job:42`、`report:monthly-2026-05`）
- **所有者**：标识锁持有者的令牌——只有所有者才能释放或续期
- **过期（TTL）**：锁自动过期，防止崩溃的所有者永久持有锁
- **过期锁接管**：已过期的锁可被新所有者接管

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

`resource` 上的 `UNIQUE` 约束确保每个资源只有一行。并发 INSERT 在数据库层面序列化。

## 获取逻辑

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // 无锁——INSERT（竞争时可能失败；调用者获得 null 后重试）
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // 已过期（陈旧）或同一所有者重新获取——UPDATE 以接管
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // 被另一所有者持有且仍有效——无法获取
    return null;
}
```

返回值约定：
- 成功时返回 `LockRecord`（API 响应中 `acquired: true`）
- 锁被另一所有者持有时返回 `null`（`acquired: false`）

## 强制所有者释放

只有所有者可以释放。所有者不匹配时返回 403（而非 404），告知调用者锁存在但他们不持有它：

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## TTL 续期

长时间运行的任务需要在锁过期前延长它。只有当前所有者可以续期——错误所有者的续期请求返回 409（而非 403），因为这表示状态冲突而非权限拒绝：

```php
if ($existing->isExpired($now)) {
    return null; // → 409：无法续期已过期的锁（其他人现在可能持有它）
}
if ($existing->owner !== $owner) {
    return null; // → 409：所有者错误
}
// 延长 expires_at
```

## 陈旧锁检测

`LockRecord::isExpired()` 将当前时间与 `expires_at` 进行比较：

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

这意味着 `GET /locks/{resource}` 对已过期的锁返回 404（将过期视为不存在），而 `POST /locks/{resource}` 允许新所有者获取已过期的锁。

## 设计决策

**为什么不用 Redis SETNX？**
Redis 在单个命令中提供带 TTL 的原子 SETNX，是高吞吐量锁定的生产标准。基于数据库的锁部署更简单（无需额外服务），与其余事务数据保持一致，对于中低竞争场景（后台任务、报告生成、批量处理）足够使用。

**为什么不在重新获取时使用 DELETE+INSERT？**
UPDATE 保留行 ID 且是原子的。DELETE+INSERT 会创建一个短暂的窗口，此时没有锁行存在，允许并发进程 INSERT 并窃取锁。

**为什么将 `acquired_at` 和 `expires_at` 分开？**
`acquired_at` 是最后建立所有权的时间戳（用于审计）。`expires_at` 在续期时更改。分开存储避免了歧义。

**非阻塞设计**
锁端点立即返回 `acquired: false`，而不是阻塞等待锁可用。调用者根据自己的超时需求实现重试策略（指数退避、死信队列等）。
