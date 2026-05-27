# 操作指南：货币与整数运算

> **相关场景**：DX Scenario 10、23、32、36、40、43、44、50——在金融场景中被最频繁引用的静默精度 bug 来源。

以浮点数（`REAL` / `float`）存储的货币金额会积累舍入误差。`1001 * 0.05` 在 IEEE 754 中产生 `50.049999999999997`，而非 `50.05`。正确的做法是以**最小货币单位的整数**存储和计算金额（日元用日元，美元/欧元用美分）。

---

## 规则：始终以整数存储

```php
// ❌ 错误——REAL/float 会积累误差
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ 正确——整数运算
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50（截断）
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

数据库结构：

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ INTEGER，不是 REAL
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

使用 `CHECK` 约束在 DB 层强制非负值。

---

## 选择舍入函数

整数除法时，必须决定如何处理余数。**在写代码之前确定并记录此策略**——事后更改会影响每条历史记录。

| 函数 | 行为 | 示例：`intdiv(1, 3)` | 适用场景 |
|----------|----------|------------------------|-------------|
| `intdiv($a, $b)` | 截断趋零 | `0` | 平台手续费（付款方保留余数） |
| `(int) round($a / $b)` | 四舍五入 | `0`（四舍五入为 0） | 分摊账单、通用舍入 |
| `(int) ceil($a / $b)` | 向上取整 | `1` | 税额计算（政府要求始终向上取整） |
| `(int) floor($a / $b)` | 向下取整 | `0` | 对正数与 intdiv 相同 |

### 平台手续费（5%）——余数归谁？

```php
// 选项 A：平台取向下取整（对付款方有利）
$fee = intdiv($amount * 5, 100);     // 1001 日元 → 手续费 = 50，卖家得 951

// 选项 B：平台取向上取整（对平台有利）
$fee = (int) ceil($amount * 5 / 100); // 1001 日元 → 手续费 = 51，卖家得 950

// 选项 C：四舍五入（中立）
$fee = (int) round($amount * 5 / 100); // 1001 日元 → 手续费 = 50，卖家得 951
```

没有普遍正确的答案。**在 API 规范中记录选择。**

---

## 税额计算（日本消费税：10%）

日本消费税要求**每笔交易截断**（而非每行项目）：

```php
// ✅ 在交易层面截断
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ 不要对每个行项目单独舍入再求和——舍入误差会累积
$items = [100, 100, 100]; // 3 件 × 100 日元
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // 可能与 intdiv(300 * 110, 100) 不同
```

如果需要存储 `tax_rate`，以**基点**（整数，1/10000）存储：`10% = 1000 bps`。避免税率本身的浮点数存储。

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10.00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## 均摊/分摊：余数分配

将总金额在 N 个参与者之间分摊时：

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // 每人份额（截断）
    $remainder = $totalYen % $n;              // 剩余日元（0 到 n-1）

    $shares = array_fill(0, $n, $base);

    // 将余数逐一分配给前几位参与者
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // 验证：求和必须等于原始总额
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (总和 = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (总和 = 100)  ✅
```

切勿对每个参与者使用 `round($total / $n)` 了事——总和往往会相差 1 日元。

---

## SQLite 整数除法陷阱

在 SQLite 中，两个整数相除执行整数除法：

```sql
SELECT 5 / 100;     -- → 0  （整数除法：截断）
SELECT 5.0 / 100;   -- → 0.05 （实数除法）
SELECT 5 * 100 / 100;  -- → 5 （先相乘再除——正确）
```

**在 PHP** 中使用 PDO 时，所有绑定值作为字符串发送。SQLite 会强制类型转换，但：

```php
// 安全：先相乘以避免截断
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → 先 amount_yen * 5（整数 × 整数 = 整数），再 / 100

// 有风险：如果 PDO 将 '5' 和 '100' 作为字符串发送，SQLite 可能选择实数除法
// 如果 SQLite 版本或 PDO 行为不确定，请测试此行为。
```

最安全的做法：**在 PHP 中使用 `intdiv()` 进行运算**，存储结果，只在 SQL 中使用求和运算（`SUM`、`COUNT`），而不是逐行计算。

---

## 折旧（直线法）

```php
// 年折旧额（直线法）
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// 当前账面价值
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` 截断年折旧额，这意味着资产每年折旧略少，余数在最后一年以额外折旧的形式出现。这是日本直线折旧的标准行为。

---

## 向用户展示

只在响应层进行人类可读格式的转换，绝不在领域层：

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1,234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

存储 `amountYen`（整数）用于进一步计算；`displayAmount`（字符串）用于 UI。绝不存储格式化字符串——它们无法求和。

---

## 总结：决策清单

在编写任何货币计算之前，回答以下问题：

1. **单位**：日元（无小数）、美分（1/100）还是微分（1/1000）？
   → 以该单位的整数存储；在列名中记录单位（`amount_yen`、`price_cents`）。

2. **舍入方向**：`intdiv`（截断）、`ceil`、`floor` 还是 `round`？
   → 选择一种；在代码中加注释说明原因。

3. **余数归谁**：分摊时，谁承担舍入差？
   → 显式分配余数（参见上面的 `splitEvenly`）。

4. **税率存储**：基点（`INTEGER`）而非百分比（`REAL`）？
   → 10% 用 `1000`，8% 用 `800`，绝不用 `0.10` 或 `0.08`。

5. **累计还是单笔**：逐行项目累计税额还是按发票总额？
   → 单笔（单次 `intdiv`）是日元发票的标准做法。

---

## 相关操作指南

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — 使用 `Money` 值对象的复式记账账本
- [`point-ledger-api.md`](point-ledger-api.md) — 使用整数金额的积分/信用系统
- [`expense-tracking-api.md`](expense-tracking-api.md) — 以整数日元记录费用
