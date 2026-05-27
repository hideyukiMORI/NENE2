# 操作指南：多货币整数分账本

> **FT 参考**：FT262（`NENE2-FT/moneylog`）——使用整数最小单位（美分）和 `Money` 值对象的多货币账本 API

演示一个复式记账风格的账本 API，以整数最小单位（美分、日元、便士）存储货币金额，以避免浮点精度误差。`Money` 值对象强制不变条件：正数金额和 3 字符 ISO 4217 货币代码。每种货币的余额通过单条 SQL 查询中的 `SUM(CASE WHEN type = 'credit' ...)` 计算。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|-----------------|---------------------------------------------|
| `POST` | `/entries`      | 创建账本条目（贷记或借记）     |
| `GET`  | `/entries`      | 列出条目（带分页）                    |
| `GET`  | `/entries/{id}` | 获取单个条目                              |
| `GET`  | `/balance`      | 每种货币的余额（贷记 − 借记）       |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` 在 DB 层强制正数金额——这是针对 bug 或直接 DB 访问的安全网。`CHECK(length(currency) = 3)` 强制 ISO 4217 格式。`CHECK(type IN ('credit', 'debit'))` 防止无效状态。

---

## 为什么用整数分而非浮点数？

```php
// ❌ 浮点运算会丢失精度
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ 整数运算精确无误
$total = 10 + 20;     // int(30) — 始终精确
```

以 `FLOAT` 存储的货币金额在求和时会积累舍入误差，并且无法用 `===` 可靠地比较。整数最小单位（美元/欧元用美分，日元用日元）始终精确。显示转换（`$cents / 100.0`）只在序列化时发生，而非在业务逻辑中。

**注意**：`JPY` 和类似的零小数位货币将整数单位作为"分"存储（即 ¥1000 = 1000 分）。此 FT 中的 `formatDecimal()` 使用固定 2 位小数默认值；生产实现应查阅货币的小数位数。

---

## `Money` 值对象

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

构造函数验证自身的不变条件。一个已存在的 `Money` 对象始终是有效的——调用者无需重新检查值。`readonly` 防止构造后被修改。

`formatDecimal()` 仅用于展示。绝不存储或比较格式化字符串；始终比较 `amountCents` 整数。

---

## `EntryType` backed 枚举

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

水化时 `EntryType::from('credit')` 将 DB 字符串转换为枚举。如果 DB 中存在意外值，`from()` 会抛出异常——不会静默损坏数据。

控制器中的 `EntryType::tryFrom($value)` 对未知值返回 `null`，校验错误检查随后捕获：

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## 每种货币余额：`SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

单条查询为每种货币计算三个聚合值：
- `credit_cents`：总贷记
- `debit_cents`：总借记
- `balance_cents`：净余额（`贷记 − 借记`）

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` 使用符号翻转在一次扫描中计算净值。负的 `balance_cents` 表示借记超过贷记。

**替代方案**：两条查询（分别 `SELECT SUM WHERE type = 'credit'` 和 `SELECT SUM WHERE type = 'debit'`），在 PHP 中合并。单条查询方式更高效，并将减法逻辑保留在 SQL 中。

---

## 控制器：货币规范化

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← 规范化为大写
);
```

`strtoupper()` 规范化货币代码，使 `usd`、`USD` 和 `Usd` 都存储为 `USD`。不进行规范化的话，`USD` 和 `usd` 会在余额查询中显示为不同货币。

---

## 序列化：同时返回分和小数

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // 机器可读：精确整数
        'amount'       => $entry->money->formatDecimal(), // 人类可读："10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

同时返回 `amount_cents`（整数）和 `amount`（格式化小数）。执行计算的客户端应使用 `amount_cents`；展示 UI 可以使用 `amount`。

---

## 示例：余额响应

**请求**：`GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

EUR 余额：500.00 − 200.00 = 300.00 EUR。USD 余额：1500.00 − 750.00 = 750.00 USD。

---

## 设计比较

| 存储方式 | 精度 | 权衡 |
|---|---|---|
| `INTEGER` 分 | 精确 | 需要显示转换；货币必须指定小数位数 |
| `DECIMAL(19,4)` | 精确 | DB 原生；SQLite 不支持；格式化显示 |
| `FLOAT`/`REAL` | 有损 | 绝不用于货币——舍入误差会累积 |
| `TEXT`（"10.50"）| 不适用 | 排序和求和需要转型；SQL 中无法算术 |

SQLite 的 `INTEGER` 加分是最简洁的 SQLite 支持的 API 安全方式。对于 MySQL/PostgreSQL，`DECIMAL(19,4)` 更为惯例。

---

## 相关操作指南

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — 资金转账的原子多写操作
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — 带部分成功的批量条目导入
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — 带 SQL 窗口函数的聚合查询
