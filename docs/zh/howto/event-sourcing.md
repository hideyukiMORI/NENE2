# 事件溯源（基础）

以不可变的领域事件序列持久化状态。通过重放事件流来派生当前状态。

## 概述

事件溯源存储**发生了什么**（事件），而不是**当前是什么**（当前状态）。账户余额不被存储；它通过重放所有存款和取款事件来计算。事件是不可变的——永远不会被更新或删除。

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
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` 是聚合根。`events` 是仅追加的事件日志。没有 `balance` 列——它始终从事件计算得出。

## 事件类型

将事件类型定义为常量以防止拼写错误并启用静态分析：

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## 追加事件

事件只会被插入，永远不会被更新。API 没有修改或删除事件的端点：

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## 重放状态

按插入顺序加载事件，并将它们折叠为当前状态：

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

`ORDER BY id ASC` 保证重放顺序。`ORDER BY occurred_at ASC` 不可靠——具有相同时间戳的两个事件顺序不确定。

## 金额校验

在追加事件前严格校验金额：

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` 拒绝浮点值（例如 `1.9`），否则 PHP 会静默截断为 `1`
- 上限防止多次大额存款相加时的整数溢出
- 在 API 层拒绝——不要让无效金额进入事件日志

## 余额不足

在追加取款事件前检查余额：

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

余额检查在处理器中进行（不在仓库中），因为这是业务规则，而非数据完整性约束。

## 事件隔离

事件通过 `aggregate_id` 限定于其聚合。重放账户 A 的事件永远不会影响账户 B：

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## 安全特性

| 特性 | 实现 |
|------|------|
| 事件不可变性 | 事件上没有 DELETE/UPDATE 端点 |
| 金额范围 | 1–1,000,000,000（整数）——拒绝浮点数和溢出值 |
| 余额不足 | 取款前重放余额；不足时返回 422 |
| 跨账户隔离 | 所有查询按 aggregate_id 过滤 |
| 载荷注入 | 载荷始终为 `['amount' => int]`；无用户控制的键 |
| 事件类型注入 | 事件类型始终来自常量；无用户控制的 event_type |

## 路由概览

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/accounts` | 创建账户 |
| `POST` | `/accounts/{id}/deposit` | 追加存款事件 |
| `POST` | `/accounts/{id}/withdraw` | 追加取款事件（检查余额） |
| `GET` | `/accounts/{id}/balance` | 从事件重放余额 |
| `GET` | `/accounts/{id}/events` | 列出账户的所有事件 |
