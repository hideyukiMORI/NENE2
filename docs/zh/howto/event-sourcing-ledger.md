# 操作指南：事件溯源账本

> **FT 参考**：FT310（`NENE2-FT/eventsourcelog`）——事件溯源账户账本：不可变事件日志（仅追加），`replayBalance()` 重放所有事件计算当前余额，存款/取款事件永不删除，`is_int()` 严格金额校验，最大金额 1,000,000,000，独立账户不共享余额，17 个测试 / 24 个断言全部通过。

本指南演示如何使用事件溯源实现账户账本：当前余额不直接存储——它通过重放所有历史事件派生得出。

## 数据库结构

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON: { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` 是仅追加的。事件上没有 `UPDATE` 或 `DELETE` 操作。每次存款或取款都追加一行新记录。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/accounts` | 创建账户 |
| `GET` | `/accounts/{id}/balance` | 获取当前余额（通过重放计算） |
| `POST` | `/accounts/{id}/deposit` | 追加存款事件 |
| `POST` | `/accounts/{id}/withdraw` | 追加取款事件 |
| `GET` | `/accounts/{id}/events` | 列出所有事件 |

## 余额——从事件重放计算

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

余额不存储在任何地方——它通过重放所有事件实时计算。新账户从 0 开始（无事件）。事件日志是唯一的真实来源。

## 存款校验

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) {
    return $this->problems->create($request, 'validation-failed',
        'amount must be a positive integer not exceeding 1000000000.', 422, '');
}
// 追加事件
$this->repo->appendEvent($id, 'AccountDeposited', ['amount' => $amount], date('c'));
```

- `is_int()` 拒绝浮点数、字符串、null
- `> 0` 拒绝零和负数
- `<= 1_000_000_000` 限制单次交易金额上限

## 取款——先检查重放余额

```php
$balance = $this->repo->replayBalance($id);
if ($amount > $balance) {
    return $this->problems->create($request, 'validation-failed',
        'insufficient funds.', 422, '');
}
$this->repo->appendEvent($id, 'AccountWithdrawn', ['amount' => $amount], date('c'));
```

在接受取款前先重放余额。透支检查在应用层进行——而非 DB 约束（事件行没有"之后余额"的概念）。

## 账户隔离

每个账户有自己的 `aggregate_id`。`replayBalance()` 按 `aggregate_id` 过滤，因此：
- 账户 1 的存款不影响账户 2 的余额
- 事件列表按账户独立（无交叉污染）

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 存储 `balance` 列而非通过重放计算 | 可变状态可能失去同步；事件是真实来源 |
| 允许事件删除 | 删除事件会使余额计算在历史上出错 |
| 接受浮点数 `amount` | 事件载荷中的小数金额会破坏整数重放 |
| 不在应用层进行透支检查 | 由于事件没有余额约束，可能产生负余额 |
| 无 `aggregate_id` 过滤的共享事件表 | 所有账户共享同一事件流 |
| 为一个账户的余额重放所有账户的事件 | 全表扫描而非按账户过滤 |
