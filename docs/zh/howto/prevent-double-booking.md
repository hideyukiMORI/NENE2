# 如何防止重复预订（预约与容量强制执行）

预约系统有两种必须分别处理的不同失败模式：

1. **重复预约**——同一用户尝试预订同一个时间段两次
2. **超出容量**——预约数量将超过该时间段的限制

两者都导致 INSERT 被拒绝，但需要不同的错误响应。本指南展示如何区分它们并防范并发冲突。

---

## 1. 数据库结构：UNIQUE 约束 + 容量列

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- 防止重复预约的最后防线
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

`UNIQUE(slot_id, user_id)` 约束是安全网——即使应用逻辑有 bug，它也能防止重复预约。但它无法告诉你 INSERT 失败的*原因*。

---

## 2. 通过明确检查区分重复与超容量

`DatabaseConstraintException` 不携带触发了哪个约束的列级信息。要返回不同的 409 响应，需要在 INSERT 之前检查每个条件：

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. 首先检查重复预约
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. 检查剩余容量
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // 调用者将 null 映射为 409 slot-full
    }

    // 3. 插入——UNIQUE 约束是最终守护
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

对于面向用户的业务规则，使用**领域异常**（`AlreadyReservedException`），而非 `DatabaseConstraintException`——后者表示数据库层事件，而非业务条件。

---

## 3. 处理器：映射到不同的 409 响应

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. 在 SQL 中计算可用性（避免 N+1）

在与时间段获取相同的查询中统计预约数：

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

然后 `available = capacity - reserved`。永远不要获取所有预约后在 PHP 中统计。

---

## 5. 并发：TOCTOU 以及何时重要

显式先检查后插入的模式存在 **TOCTOU（检查时间/使用时间）窗口**：两个并发请求都可能通过容量检查，然后都尝试插入。

| 数据库 | 行为 |
|---|---|
| **SQLite** | 每数据库写入序列化：一次只有一个写入运行。第二个 INSERT 触及 UNIQUE 约束并抛出 `DatabaseConstraintException`。安全。|
| 高并发下的 **PostgreSQL** | 两个带不同 `user_id` 的请求可能都通过 `available > 0` 检查并都 INSERT，短暂超出容量 1 个。UNIQUE 约束不触发（不同用户）。|

**PostgreSQL 的修复方案**：在 `SERIALIZABLE` 事务中包装检查和 INSERT，或在读取之前使用 `SELECT ... FOR UPDATE` 锁定时间段行：

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // 此闭包中的所有查询共享相同的可序列化快照
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

对于 SQLite，UNIQUE 约束单独即足以提供保护。

---

## 6. 测试并发场景

顺序测试无法重现真正的并发，但它们可以验证意图：

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // "同时"到达

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // 时间段已满

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // 取消后，bob 可以预订
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## 注意事项

- UNIQUE 约束是**最后防线**——它捕获应用逻辑中的 bug。永远不要依赖它作为主要容量强制执行机制，因为它无法区分重复用户和超出容量。
- **取消并重新预约**：当用户取消时，从 `reservations` 中删除。容量计数通过 `COUNT(r.id)` 查询自动递减。无需显式的"释放时间段"更新。
- **幂等取消**：`DELETE WHERE slot_id = ? AND user_id = ?` 在预约不存在时返回 0 行——将此映射为 404，而非 500。
