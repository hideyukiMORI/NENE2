# 积分忠诚度系统

带积分赚取、消费、余额管理和管理员调整的忠诚度系统实现指南。解说幂等交易（reference_id）、余额下限保护以及管理员 RBAC。

## 概述

- 用户可以赚取（earn）和消费（spend）积分
- 只有管理员可以直接调整（adjust: add/subtract）积分
- 余额通过累积交易的 `balance_after` 来管理
- 通过 `reference_id` 实现幂等交易（防止双重赋予、双重消费）
- 通过单笔交易金额上限（MAX_EARN_PER_TRANSACTION）防止批量非法赋予

## 端点

| 方法 | 路径 | 描述 | 权限 |
|---|---|---|---|
| `GET` | `/users/{userId}/points` | 获取余额 | 本人或管理员 |
| `GET` | `/users/{userId}/points/history` | 获取历史 | 本人或管理员 |
| `POST` | `/users/{userId}/points/earn` | 赚取积分 | 本人或管理员 |
| `POST` | `/users/{userId}/points/spend` | 消费积分 | 本人或管理员 |
| `POST` | `/users/{userId}/points/adjust` | 调整积分 | 仅管理员 |

## 数据库设计

```sql
CREATE TABLE point_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    amount INTEGER NOT NULL CHECK (amount > 0),
    balance_after INTEGER NOT NULL CHECK (balance_after >= 0),
    description TEXT NOT NULL,
    reference_id TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`balance_after >= 0` 的 CHECK 约束在 DB 层防止余额为负。
`amount > 0` 的 CHECK 约束在 DB 层拒绝零值或负值交易。

## 余额计算

```php
public function getBalance(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

最新交易的 `balance_after` 即为当前余额。不在单独的表中维护余额，因此交易历史是唯一的真实来源（SSOT）。

## 幂等交易（reference_id）

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
// ... 处理新交易
```

如果 `reference_id` 已存在，返回现有交易（200）。防止双重信用和双重扣费。

## 积分消费的余额检查

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error' => 'insufficient points',
        'balance' => $balance,
        'required' => $amount,
    ], 422);
}
$balanceAfter = $balance - $amount;  // 必然 >= 0
```

在应用层进行余额检查，与 DB 的 CHECK 约束形成双重防御。

## 管理员调整（adjust）

```php
// adjust_type: 'add'（默认）或 'subtract'
if ($adjustType === 'subtract') {
    if ($balance < $amount) { return 422 'insufficient points for adjustment'; }
    $balanceAfter = $balance - $amount;
} else {
    $balanceAfter = $balance + $amount;
}
$this->repository->addTransaction($userId, 'adjust', $amount, $balanceAfter, $description, null, $now);
```

## 上限控制（MAX_EARN_PER_TRANSACTION）

```php
private const int MAX_EARN_PER_TRANSACTION = 10000;

if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max' => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

防止单次交易中大量非法赋予积分的攻击。

## 访问控制

只有本人可以查看自己的余额和历史。管理员可以查看和操作所有用户。

```php
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

earn/spend 只能操作自己的积分（无法增加他人的积分）。

## 响应示例

### POST /users/2/points/earn
```json
{
  "id": 1,
  "user_id": 2,
  "type": "earn",
  "amount": 100,
  "balance_after": 100,
  "description": "Purchase reward",
  "reference_id": "order-123",
  "created_at": "2026-05-21T..."
}
```

### GET /users/2/points/history
```json
{
  "user_id": 2,
  "balance": 70,
  "transactions": [
    {"id": 2, "type": "spend", "amount": 30, "balance_after": 70, ...},
    {"id": 1, "type": "earn", "amount": 100, "balance_after": 100, ...}
  ]
}
```
