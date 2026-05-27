# 操作指南：事件溯源与 CQRS API

> **FT 参考**：`NENE2-FT/eventstore`——带每聚合序列号的仅追加事件日志，白名单事件类型，从事件重建的读模型投影，余额追踪，17 个测试全部通过。

本指南演示如何实现事件溯源：将每次状态变更存储为不可变事件，从事件日志计算当前状态，并暴露读模型投影。

## 数据库结构

```sql
-- 仅追加事件日志——绝不 UPDATE 或 DELETE 行
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- 例如 "acc-001"
    aggregate_type TEXT    NOT NULL,  -- 例如 "account"
    event_type     TEXT    NOT NULL,  -- 例如 "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- 每聚合计数器，从 1 开始
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- 读模型：从事件重建的当前账户状态
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` 防止重复插入事件。投影始终从事件日志派生——可以通过重放随时重建。

## 事件类型（白名单）

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

未知事件类型返回 422。只追加在投影逻辑中有显式处理器的事件。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/accounts` | 开户（触发 AccountOpened） |
| `GET` | `/accounts` | 列出所有账户投影 |
| `GET` | `/accounts/{id}` | 获取账户投影（未找到时返回 404） |
| `POST` | `/accounts/{id}/events` | 向账户追加事件 |
| `GET` | `/accounts/{id}/events` | 列出账户的事件日志 |

## 开户

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

开户会创建一个 `AccountOpened` 事件（sequence=1）并初始化投影。

## 追加事件

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← 每聚合递增
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

每个账户有**独立的序列计数器**。`acc-001` 和 `acc-002` 都从 1 开始。

```php
// 无效事件类型 → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// 不存在的账户 → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## 读模型投影

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 存款 + 10000 存款
  "is_open": true,
  "last_sequence": 3
}

// 应用 AccountClosed 事件后
GET /accounts/acc-001
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## 事件日志

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

按 `sequence ASC` 排序——按时间顺序。

```php
// 未知账户 → 返回空列表（不是 404）
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## 实现

### 序列号生成

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### 追加事件 + 更新投影（事务）

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // 追加到不可变日志
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // 更新投影
        $this->applyProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }

    return $this->db->fetchOne('SELECT * FROM domain_events WHERE id = ?', [$id]);
}
```

### 投影逻辑

```php
private function applyProjection(
    string $aggregateId,
    string $eventType,
    array $payload,
    int $sequence,
    \DateTimeImmutable $now,
): void {
    $ts = $now->format('Y-m-d H:i:s');
    match ($eventType) {
        'AccountOpened' => $this->db->execute(
            'INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
             VALUES (?, ?, 0, 1, ?, ?)',
            [$aggregateId, $payload['owner'] ?? '', $sequence, $ts],
        ),
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $ts, $aggregateId],
        ),
        default => null,
    };
}
```

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 在 `domain_events` 中 UPDATE 或 DELETE 行 | 破坏审计追踪；投影与历史不一致 |
| 无 `UNIQUE(aggregate_id, sequence)` | 重复事件在重放时污染投影 |
| 在 `domain_events` 中存储计算后的余额 | 派生状态属于投影，而非事件日志 |
| 允许任意事件类型 | 投影逻辑没有处理器 → 静默无操作或崩溃 |
| 追加事件时不在同一事务中更新投影 | 事件日志与读模型之间存在不一致窗口 |
| 无每聚合序列计数器 | 无法检测重放缺口或并发写入冲突 |
