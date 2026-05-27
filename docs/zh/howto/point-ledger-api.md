# 操作指南：积分账本 API

> **FT 参考**：FT300（`NENE2-FT/pointlog`）——积分账本 API：赚取/消费/调整/过期交易、余额追踪、超额提款防护（CHECK balance_after >= 0）、仅管理员调整、reference_id 幂等性、MAX_EARN=10000 / MAX_ADJUST=100000 上限，ATK-01~12 全部 BLOCKED，30 个测试 / 66 个断言全部通过。

本指南展示如何构建忠诚度积分账本，用户可以赚取和消费积分，管理员可以调整余额，reference ID 防止重复交易。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

三个 CHECK 约束作为纵深防御：
- `amount > 0`——DB 层不允许零值或负值交易
- `balance_after >= 0`——存储中余额永不为负
- `type IN (...)`——只接受已知的交易类型

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | 获取当前余额 |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | 获取交易历史 |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id`（本人） | 赚取积分 |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id`（本人） | 消费积分 |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id`（管理员） | 管理员调整 |

## 认证与授权

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

每个处理器首先调用 `requireUserId()`：

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

然后对赚取/消费检查跨用户访问：

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

管理员可以查看任意用户的余额或历史。非管理员只能访问自己的。

## 严格整数校验

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` 拒绝：
- 浮点数：`10.5`——拒绝（422）
- 字符串：`"100"`——拒绝（422）
- 布尔值：`true`——拒绝（422）
- 零：`0`——拒绝（amount <= 0）
- 负数：`-500`——拒绝（amount <= 0）

## 交易上限

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

赚取每笔交易上限为 10,000。管理员调整上限为 100,000（更高，因为这是特权修正操作）。

## 超额提款防护

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

消费前检查当前余额。在错误中返回当前余额和所需金额，帮助客户端向用户显示有意义的消息。

## 管理员调整

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

调整**先**检查 `isAdmin()`，再检查目标用户——非管理员无论目标是谁都立即得到 403。`adjust_type` 字段（默认 `'add'` / `'subtract'`）让管理员无需单独端点即可授予和扣除积分。

## reference_id 幂等性

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

当提供 `reference_id` 时：
- 首次调用 → 201 Created，创建新交易
- 使用相同 `reference_id` 重复调用 → 200 OK，返回原始交易（不创建新交易）

这防止网络重试时的重复信用。reference_id 查找是**用户范围的**（`findByReferenceId($targetUserId, ...)`），因此不同用户可以使用相同的 reference_id 而不冲突。

## 余额计算

```php
// 仓储：最近一条交易的 balance_after，若无则为 0
public function getBalance(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

每条交易上的 `balance_after` 列存储累计余额。获取当前余额只需一次 `ORDER BY id DESC LIMIT 1` 查询——不需要 SUM 聚合。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 未认证访问余额 🚫 BLOCKED

**攻击**：`GET /users/2/points`，无 `X-User-Id` 请求头。
**结论**：🚫 BLOCKED——`requireUserId()` 返回 null → 立即返回 401。不返回数据。

---

### ATK-02 — 跨用户余额窥视 🚫 BLOCKED

**攻击**：`GET /users/2/points`，带 `X-User-Id: 3`（Alice 尝试读取 Bob 的余额）。
**结论**：🚫 BLOCKED——`$targetUserId (2) !== $actorId (3)` 且非管理员 → 403。

---

### ATK-03 — 向其他用户自赠 🚫 BLOCKED

**攻击**：`POST /users/3/points/earn`，带 `X-User-Id: 2` 和 `amount: 99999`。
**结论**：🚫 BLOCKED——操作者（2）≠ 目标（3）且非管理员 → 403。目标余额保持为 0。

---

### ATK-04 — 负数金额赚取 🚫 BLOCKED

**攻击**：`POST /users/2/points/earn`，带 `amount: -500`。
**结论**：🚫 BLOCKED——`$amount <= 0` 检查 → 422。余额不变。

---

### ATK-05 — 零金额交易 🚫 BLOCKED

**攻击**：`POST /users/2/points/earn`，带 `amount: 0`，以及 spend 也带 `amount: 0`。
**结论**：🚫 BLOCKED——两者都返回 422（`amount <= 0`）。不创建零值交易。

---

### ATK-06 — 超额消费 🚫 BLOCKED

**攻击**：赚取 100 积分，然后尝试消费 101。
**结论**：🚫 BLOCKED——`$balance (100) < $amount (101)` → 422，带 `insufficient points`。余额保持 100。DB `CHECK (balance_after >= 0)` 提供额外的最后防线。

---

### ATK-07 — 普通用户调整 🚫 BLOCKED

**攻击**：`POST /users/2/points/adjust`，带 `X-User-Id: 2`（非管理员角色）。
**结论**：🚫 BLOCKED——`isAdmin()` 检查失败 → 403。余额保持 0。

---

### ATK-08 — 赚取金额过大 🚫 BLOCKED

**攻击**：`POST /users/2/points/earn`，带 `amount: 10001`（超过 MAX_EARN=10000）。
**结论**：🚫 BLOCKED——`$amount > MAX_EARN_PER_TRANSACTION` → 422，带 `max: 10000`。余额不变。

---

### ATK-09 — 通过重用 reference_id 双重信用 🚫 BLOCKED

**攻击**：用 `reference_id: "order-999"` 赚取 500 积分，然后重复相同请求。
**结论**：🚫 BLOCKED——第二次调用通过 `findByReferenceId()` 找到现有交易 → 200，返回相同交易。余额保持 500（而非 1000）。

---

### ATK-10 — 通过重用 reference_id 双重扣除 🚫 BLOCKED

**攻击**：用 `reference_id: "redemption-777"` 消费 300 积分，然后重复。
**结论**：🚫 BLOCKED——第二次调用返回原始消费交易（200）。余额保持 700（而非 400）。

---

### ATK-11 — reference_id 中的 SQL 注入 🚫 BLOCKED

**攻击**：在赚取请求中使用 `reference_id: "' OR '1'='1' --"`。
**结论**：🚫 BLOCKED——参数化查询将注入字符串逐字存储。余额为 100，未损坏。响应中的 `reference_id` 与注入字符串完全匹配（作为数据存储，不解释为 SQL）。

---

### ATK-12 — 浮点数金额 🚫 BLOCKED

**攻击**：`POST /users/2/points/earn`，带 `amount: 10.5`。
**结论**：🚫 BLOCKED——`is_int(10.5)` 为 false → null → 422。余额不变。

---

### ATK 汇总

| ID | 攻击 | 结论 |
|----|--------|--------|
| ATK-01 | 未认证余额访问 | 🚫 BLOCKED |
| ATK-02 | 跨用户余额窥视 | 🚫 BLOCKED |
| ATK-03 | 向其他用户自赠 | 🚫 BLOCKED |
| ATK-04 | 负数金额赚取 | 🚫 BLOCKED |
| ATK-05 | 零金额交易 | 🚫 BLOCKED |
| ATK-06 | 超额消费 | 🚫 BLOCKED |
| ATK-07 | 普通用户调整 | 🚫 BLOCKED |
| ATK-08 | 赚取金额超过最大值 | 🚫 BLOCKED |
| ATK-09 | 通过 reference_id 双重信用 | 🚫 BLOCKED |
| ATK-10 | 通过 reference_id 双重扣除 | 🚫 BLOCKED |
| ATK-11 | reference_id 中的 SQL 注入 | 🚫 BLOCKED |
| ATK-12 | 浮点数金额 | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED**
无重大发现。认证链（401→403）、金额校验（is_int + >0 + 上限）、超额提款守护以及 reference_id 幂等性覆盖了所有已知攻击向量。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 无 `X-User-Id` 检查（跳过认证） | 未认证访问所有余额和交易 |
| 无管理员检查的跨用户赚取 | 任意用户向任意其他用户的账户赚取积分 |
| `$amount > 0` 无 `is_int()` | 浮点数 `10.5` 通过；小数积分破坏账本完整性 |
| 无 MAX_EARN 上限 | 攻击者一次请求赚取 INT_MAX 积分 |
| 消费前无超额提款检查 | 余额变为负数；DB CHECK 是最后手段，不是主要守护 |
| 无 `reference_id` 幂等性 | 网络重试导致双重信用或扣费 |
| 跨用户共享 `reference_id` 空间 | 用户 A 的 `order-1` 阻止用户 B 使用相同引用 |
| 大表上通过 SUM 聚合获取 `getBalance()` | 每次请求全表扫描；改用 `balance_after` 累计余额 |
| 先检查业务逻辑再检查管理员调整角色 | 非管理员提交大量调整；先检查角色再检查任何业务逻辑 |
| 重复时返回 200 但响应体不同 | 客户端无法验证幂等性；必须返回原始交易 |
