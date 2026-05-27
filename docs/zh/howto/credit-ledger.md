# 操作指南：积分账本 API

> **FT 参考**：FT234（`NENE2-FT/creditslog`）——积分账本 API

演示一个 append-only 积分账本，余额从不直接存储——它们在查询时通过 `SUM(amount * direction)` 计算得出。支持积累积分、消费积分（带透支保护）、通过唯一键的幂等积累，以及可过滤的交易历史。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/users/{userId}/credits/earn` | 积累积分（增加余额） |
| `POST` | `/users/{userId}/credits/spend` | 消费积分（减少余额，透支时返回 409） |
| `GET` | `/users/{userId}/credits/balance` | 获取当前余额 |
| `GET` | `/users/{userId}/credits/transactions` | 列出交易历史（可选 `?type=`） |

---

## 账本模型：使用 `direction` 而非带符号的金额

不存储正负金额，每笔交易存储正数 `amount` 和有符号的 `direction`（积累为 `+1`，消费为 `-1`）：

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

`direction` 列模式的优点：
- `CHECK(amount > 0)` 强制原始金额始终为正——插入时不会出现意外的双重取反 bug。
- `CHECK(direction IN (1, -1))` 将乘数约束为两个有效值。
- 余额计算公式统一：`SUM(amount * direction)`——聚合中无需条件分支。
- 提供 `adjust` 类型用于手动调整（例如退款、管理员发放），可使用任一方向。

---

## 余额计算

余额在读取时计算——从不更新 `balance` 列：

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` 处理用户没有交易的情况——SQL 中空集的 `SUM` 返回 `NULL`，虽然会被转换为 `0`，但 `COALESCE` 使意图更明确。

`user_id` 上的索引确保 `SUM` 聚合只扫描该用户的行。对于大型账本，带乐观锁或事件溯源快照的缓存余额列值得考虑（参见 `add-optimistic-locking.md`）。

---

## 带可选幂等键的积累

提供 `idempotency_key` 使积累操作可以安全重试——重复的键返回原始交易而不是插入新记录：

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // 键已使用——返回原始交易
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

`idempotency_key` 上的 `UNIQUE` 约束使数据库成为权威——应用捕获 `DatabaseConstraintException` 并重新获取已有行。这避免了先查询后插入的竞争条件：两个并发的相同键重试只会导致一个 INSERT 成功。

---

## 带透支保护的消费

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

控制器将 `InsufficientCreditsException` 映射为 `409 Conflict`：

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

`409 Conflict` 优于 `422 Unprocessable Entity`，因为请求是有效的——是余额状态阻止了它。积累更多积分后重试的调用者会成功。

> **并发说明**：余额检查和插入没有包装在事务中。两个并发的消费请求可能都读取到足够的余额并都插入，导致余额变为负数。将其包装在带 `SELECT ... FOR UPDATE`（MySQL/PostgreSQL）的事务中，或使用 SQLite 的序列化写入来保证并发正确性。

---

## 金额校验

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

`is_int()` 严格检查拒绝 JSON 浮点数（`1.5`）和字符串（`"10"`）。数据库层面的 `CHECK(amount > 0)` 作为备用，但在应用层拒绝可以提供结构化的 Problem Details 响应而非数据库错误。

---

## 带类型过滤的交易历史

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` 或 `?type=spend` 缩小列表范围。不对类型值进行校验——未知类型（例如 `?type=refund`）返回空列表而非错误，对于过滤参数来说这是可接受的。

---

## 数据库设计说明

| 列 | 用途 |
|----|------|
| `amount` | 始终为正；`CHECK(amount > 0)` 强制执行 |
| `direction` | `+1`（积累）或 `-1`（消费）；`CHECK(direction IN (1, -1))` |
| `type` | 人类可读标签：`earn`、`spend`、`adjust`；`CHECK` 允许列表 |
| `idempotency_key` | 可选的 `UNIQUE` 键，用于重试安全的积累操作 |
| `description` | 交易的自由文本备注 |

没有 `balance` 列——当前余额始终从账本中推导。

---

## 相关操作指南

- [`idempotency.md`](idempotency.md) ——通用幂等键模式
- [`multi-currency-wallet.md`](multi-currency-wallet.md) ——多币种余额管理
- [`point-loyalty-system.md`](point-loyalty-system.md) ——带等级的积分积累/兑换
- [`add-optimistic-locking.md`](add-optimistic-locking.md) ——带版本保护的缓存余额
- [`transactions.md`](transactions.md) ——将余额检查和插入包装在事务中
