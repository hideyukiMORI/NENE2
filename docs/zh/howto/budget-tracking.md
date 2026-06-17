# 操作指南：预算追踪 API

> **FT 参考**：FT244（`NENE2-FT/budgetlog`）——预算追踪 API
> **ATK**：FT244——破解者视角攻击测试（ATK-01 至 ATK-12）

演示一个多账户预算追踪 API，支持 `income`/`expense`/`transfer` 交易类型，在数据库事务中进行余额检查的 `TransferFundsUseCase`，使用 `QueryStringParser` 的多过滤条件交易列表，以及分类聚合。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|-----------------------------------|------------------------------------------------------|
| `GET` | `/accounts` | 列出所有账户 |
| `POST` | `/accounts` | 创建账户（可选初始余额） |
| `GET` | `/accounts/{id}` | 获取单个账户 |
| `POST` | `/accounts/{id}/transactions` | 记录收入或支出交易 |
| `GET` | `/accounts/{id}/transactions` | 列出交易（可过滤，可分页） |
| `GET` | `/accounts/{id}/summary` | 余额 + 按分类统计收入/支出 |
| `POST` | `/transfers` | 在两个账户间转账 |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` 和 `amount` 以整数存储（最小货币单位，如分）。`type` 在数据库层面由 `CHECK(type IN ('income','expense','transfer'))` 约束。`recurring` 以 `INTEGER`（`0`/`1`）存储，映射为 PHP `bool`。

---

## 交易类型白名单

控制器验证 `type` 是否在明确的白名单中：

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

API 只接受 `income` 和 `expense`。`transfer` 类型由 `TransferFundsUseCase` 内部设置——调用者无法通过 `POST /accounts/{id}/transactions` 直接注入。

---

## 余额更新：读取后更新模式

`POST /accounts/{id}/transactions` 在记录交易后更新账户余额：

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

首先读取余额（`findById`），在 PHP 中计算增量，然后写回（`updateBalance`）。这**不是原子操作**——并发请求可能产生竞争条件（见 ATK-09）。

---

## TransferFundsUseCase：余额检查 + 数据库事务

转账包裹在数据库事务中以确保一致性：

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // 在回调内用事务执行器实例化仓库
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

仓库在事务回调**内部**用 `$tx` 执行器实例化——这确保所有读写共享同一个连接和事务边界。如果任何步骤抛出异常，整个事务回滚。

相同账户保护在控制器中：
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## 多过滤条件交易列表

`GET /accounts/{id}/transactions` 支持多个同时过滤条件：

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` 在参数不存在时返回 `null`——无过滤。`QueryStringParser::bool()` 在不存在时返回 `null`，`"true"/"1"` 时为 `true`，`"false"/"0"` 时为 `false`。

仓库动态构建 `WHERE` 子句：

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## 分类汇总聚合

`GET /accounts/{id}/summary` 返回余额和按分类分组的总计：

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

仓库使用带 `SUM(amount)` 的 `GROUP BY category`：

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK——破解者视角攻击测试（FT244）

### ATK-01 — 无认证：账户和交易均公开

**攻击**：不提供任何凭据列出所有账户。

```bash
curl -s http://localhost:8200/accounts
curl -s http://localhost:8200/accounts/1/transactions
```

**观察结果**：两个端点不需要任何认证就返回数据。任何调用者都可以枚举所有账户及其余额。

**结论**：**EXPOSED**——对所有端点添加认证（API 密钥、JWT 或会话）。账户应有用户范围限定。

---

### ATK-02 — 创建余额为负的账户

**攻击**：绕过负余额检查。

```json
{"name": "Attack", "initial_balance": -99999}
```

**观察结果**：`$initialBalance < 0` 检查触发 → `422 Unprocessable Entity`，包含 `out_of_range` 错误。

**结论**：**BLOCKED**——明确的保护拒绝负的初始余额。

---

### ATK-03 — 支出导致账户余额变为负数

**攻击**：通过直接交易记录超过账户余额的支出。

```bash
# 账户余额为 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**观察结果**：`createTransaction` 处理器读取余额后减法时不检查是否足够。`100 - 99999 = -99899`——余额被写为负整数。

**结论**：**EXPOSED**——`POST /accounts/{id}/transactions` 不强制执行非负余额约束。只有 `POST /transfers`（通过 `TransferFundsUseCase`）检查 `if ($from->balance < $amount)`。在 `createTransaction` 的支出交易中添加余额充足性检查。

---

### ATK-04 — 通过分类或描述进行 SQL 注入

**攻击**：在 `category` 或 `description` 中嵌入 SQL 元字符。

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**观察结果**：所有值都以参数化的 `?` 值绑定。没有与 SQL 进行字符串拼接。注入载荷作为字面文本存储。

**结论**：**BLOCKED**——参数化查询防止 SQL 注入。

---

### ATK-05 — 浮点金额：`(int)` 强制转换截断

**攻击**：发送浮点金额。

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**观察结果**：`(int) $body['amount']` 将 `1.9` 截断为 `1`。金额 `1.9` 被静默接受并存储为 `1`。期望 `1.9` 被拒绝（或四舍五入为 `2`）的调用者会感到意外。

**结论**：**PARTIALLY BLOCKED**——非整数浮点数被接受并静默截断。使用 `is_int($body['amount'])` 明确拒绝非整数类型，对 `1.9` 返回 `422`。

---

### ATK-06 — 零或负金额

**攻击**：提交 `amount: 0` 或 `amount: -100`。

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**观察结果**：两者都触发 `$amount <= 0` 检查 → `422 Unprocessable Entity`。

**结论**：**BLOCKED**——明确的保护拒绝零和负金额。

---

### ATK-07 — 向同一账户转账

**攻击**：从一个账户向自身转账。

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**观察结果**：`$fromId === $toId && $fromId > 0` 触发 → `422 Unprocessable Entity`，`to_account_id` 上有 `invalid_value` 错误。

**结论**：**BLOCKED**——相同账户转账被明确拒绝。

---

### ATK-08 — 余额不足的转账

**攻击**：转账金额超过源账户余额。

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**观察结果**：在事务内部，`$from->balance < $amount` 触发 → `ValidationException`，包含 `insufficient_balance` → 事务回滚 → `422`。两个余额都不改变。

**结论**：**BLOCKED**——`TransferFundsUseCase` 在数据库事务内部检查余额。回滚确保原子性。

---

### ATK-09 — 直接支出交易的竞争条件

**攻击**：提交两个并发的支出请求，两者都能通过余额检查（根本没有检查），但合计超过余额。

**观察结果**：`createTransaction` 使用没有事务的读取后更新模式：
1. 线程 A 读取 `balance = 100`
2. 线程 B 读取 `balance = 100`
3. 线程 A 记录支出 80 → 写入 `balance = 20`
4. 线程 B 记录支出 80 → 写入 `balance = 20`（应为 -60）

`balance` 列最终为 `20` 而非正确的 `-60`——但更严重的是，对于直接交易，业务约束（非负余额）根本没有被强制执行，允许此路径绕过读取后更新。

**结论**：**EXPOSED**——`createTransaction` 路径没有余额保护，没有事务包裹。修复方法：（1）添加 `if ($type === 'expense' && $account->balance < $amount) → 422`，（2）将读取后更新包裹在数据库事务中。

---

### ATK-10 — 访问其他账户的交易（无所有权检查）

**攻击**：读取属于不同用户账户的交易。

```bash
curl -s http://localhost:8200/accounts/2/transactions
```

**观察结果**：端点返回账户 2 的所有交易，没有任何所有权检查。由于没有认证，任何调用者都可以读取任何账户。

**结论**：**EXPOSED**（与 ATK-01 根因相同）。账户必须限定为已认证用户——`WHERE account_id = ? AND owner_id = ?`。

---

### ATK-11 — `recurring` 字段：真值强制转换

**攻击**：为 `recurring` 发送非布尔值。

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**观察结果**：`(bool) $body['recurring']` 将 `"yes"` 强制转换为 `true`，`1` 为 `true`，`0` 为 `false`。任何真值字符串都会设置 `recurring = true`。没有严格的 `is_bool()` 检查。

**结论**：**PARTIALLY BLOCKED**——非布尔类型被静默强制转换。使用 `is_bool($body['recurring'])` 进行严格类型执行，对非布尔输入返回 `422`。

---

### ATK-12 — 路径中的非数字账户 ID

**攻击**：在路径参数中传递字符串 ID。

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**观察结果**：`(int) 'abc'` = `0`，`(int) '1.5'` = `1`。
- `abc` → `findById(0)` → 返回 `null` → `404 Not Found`。
- `1.5` → `findById(1)` → 如果账户 1 存在，静默返回它。

**结论**：**PARTIALLY BLOCKED**——非数字字符串映射为 404。浮点字符串被静默截断。添加 `ctype_digit()` 验证以进行严格的路径参数检查。

---

## ATK 总结

| # | 攻击向量 | 结论 |
|---|---------------|---------|
| ATK-01 | 无认证（所有端点公开） | EXPOSED |
| ATK-02 | 负的初始余额 | BLOCKED |
| ATK-03 | 支出导致余额变为负数 | EXPOSED |
| ATK-04 | 通过分类/描述进行 SQL 注入 | BLOCKED |
| ATK-05 | 浮点金额被静默截断 | PARTIALLY BLOCKED |
| ATK-06 | 零或负金额 | BLOCKED |
| ATK-07 | 向同一账户转账 | BLOCKED |
| ATK-08 | 余额不足的转账 | BLOCKED |
| ATK-09 | 直接支出的竞争条件 | EXPOSED |
| ATK-10 | 跨账户数据访问（无所有权检查） | EXPOSED |
| ATK-11 | `recurring` 非布尔强制转换 | PARTIALLY BLOCKED |
| ATK-12 | 非数字账户 ID | PARTIALLY BLOCKED |

**生产前需要修复的真实漏洞**：
1. **ATK-01 / ATK-10** ——添加认证和按用户的账户所有权
2. **ATK-03 / ATK-09** ——在 `createTransaction` 中添加余额充足性检查 + 数据库事务
3. **ATK-05** ——将 `(int)` 强制转换替换为 `is_int()` 检查以进行严格类型执行
4. **ATK-11** ——将 `(bool)` 强制转换替换为 `is_bool()` 检查
5. **ATK-12** ——为 ID 路径参数添加 `ctype_digit()` 保护

---

## 相关操作指南

- [`credit-ledger.md`](credit-ledger.md) ——带 ±1 方向和 InsufficientCreditsException 的只追加账本
- [`multi-currency-wallet.md`](multi-currency-wallet.md) ——多货币余额管理
- [`transactions.md`](transactions.md) ——DatabaseTransactionManagerInterface 模式
- [`note-management-ownership.md`](note-management-ownership.md) ——按用户的资源所有权与 IDOR 防护
