# 操作指南：产品价格历史 API

> **FT 参考**：FT67（`NENE2-FT/pricelog`）——产品价格历史 API
> **ATK**：FT228——破解者思维攻击测试（ATK-01 到 ATK-12）

演示价格历史 API，每个产品维护价格层级的时间线（有效期）。可以查询当前价格以及任意时间点的价格。ATK 部分记录了十二个攻击向量及其通过/失败裁定。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|------|------|
| `POST` | `/products` | 创建产品 |
| `GET` | `/products` | 列出所有产品 |
| `GET` | `/products/{id}` | 获取单个产品 |
| `POST` | `/products/{id}/prices` | 设置新价格（开启新层级） |
| `GET` | `/products/{id}/prices` | 列出完整价格历史 |
| `GET` | `/products/{id}/prices/current` | 当前有效价格 |
| `GET` | `/products/{id}/prices/at` | 特定时间的价格（`?datetime=`） |

---

## 价格层级模型

每个价格都有 `effective_from` 和 `effective_to` 时间戳。当满足以下条件时层级"有效"：

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` 表示该层级尚无结束日期（开放区间）。

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- 分（非负）
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = 开放（当前）
    created_at     TEXT    NOT NULL
);
```

---

## 设置价格：关闭旧层级，开启新层级

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // 关闭在新 effective_from 之前开始的任何开放层级
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // 开启新层级
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

UPDATE 关闭 `effective_from <= newEffectiveFrom` 的任何开放层级。这正确处理了三种场景：
- **新 effective_from 在未来**：在未来日期关闭当前层级。
- **新 effective_from 在过去**：回溯旧层级的关闭时间并开启新的历史层级。
- **重复的 effective_from**：在同一时刻关闭旧层级（零持续时间），然后开启新的。

> **并发注意**：UPDATE 和 INSERT 未包装在事务中。两个带相同 `effective_from` 的并发 `setPrice` 调用都可能通过 UPDATE 阶段并都 INSERT，留下两个开放层级（`effective_to IS NULL`）。查询使用 `ORDER BY effective_from DESC LIMIT 1`，因此最后插入的胜出，但历史记录已损坏。为了在并发下的正确性，请包装在 `transactional()` 中。

---

## 查询某时间点的价格

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

比较是对存储为 TEXT 的 ISO 8601 日期时间进行词典序比较。这**只有在所有日期时间使用相同格式和时区时**才能正确工作（如全部 UTC `2026-05-27 09:00:00`）。混合格式或时区偏移会产生错误结果。

**示例**：如果 `effective_from` 存储为 `"2026-05-27T09:00:00+09:00"`（JST），而 `?datetime=2026-05-27T00:30:00Z`（UTC，同一时刻），字符串比较会将其视为不同，可能返回错误的层级。在写入时将所有日期时间规范化为 UTC。

---

## 金额用分（整数）

货币值以整数（分）存储，避免浮点舍入：

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` 拒绝 JSON 浮点数（`9.99` → PHP float）和字符串。
- `$amount < 0` 拒绝负价格。
- `$amount === 0` **允许**（免费产品/促销）。

---

## ATK——破解者攻击测试（FT228）

### ATK-01 — 无认证

**攻击**：无凭证为任意产品设置价格。
**结论**：**EXPOSED**（FT67 演示版本按设计如此）。在生产环境中通过管理员角色或 API 密钥限制价格修改。

---

### ATK-02 — 回溯价格操纵

**攻击**：将 `effective_from` 设置为过去日期以修改定价历史。
**结论**：**EXPOSED**——没有认证就没有所有者来授权回溯。有认证时，要求 `effective_from >= now()`，除非调用者是管理员。

---

### ATK-03 — 通过 `?datetime=` 的 SQL 注入

**攻击**：通过 `datetime` 查询参数注入 SQL。
**结论**：**BLOCKED**——PDO 参数化语句防止 SQL 注入。

---

### ATK-04 — 零金额价格

**攻击**：将产品价格设置为零（免费）。
**结论**：**按设计接受**——`amount === 0` 是有意允许的（试用计划、促销）。如果零价格对您的业务域无效，将 `$amount < 0` 改为 `$amount <= 0`。

---

### ATK-05 — 负金额

**攻击**：设置负价格（退款攻击？）。
**结论**：**BLOCKED**——检查 `$amount < 0` 拒绝负金额。

---

### ATK-06 — 货币代码注入（无白名单）

**攻击**：设置任意或恶意货币字符串的价格。
**结论**：**EXPOSED**——针对 ISO 4217 白名单校验 `currency`：
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — 超大金额

**攻击**：提交超过 PHP/SQLite 处理能力的金额。
**结论**：**BLOCKED**——PHP 将超过 `PHP_INT_MAX`（64 位上的 2^63 - 1）的大 JSON 整数解析为 float。`is_int($body['amount'])` 对 float 返回 false → 422。

---

### ATK-08 — `?datetime=` 中无效日期时间

**攻击**：向 `priceAt` 端点传递非日期字符串。
**结论**：**部分 EXPOSED**——端点静默接受无效日期并返回 404，这可能使期望 422 的调用者困惑。添加格式校验：
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — 未来的 effective_from（计划价格）

**攻击**：将 `effective_from` 设置为未来日期以计划价格变更。
**结论**：**按设计接受**——计划定价是合法用例。在 API 规范中记录它。

---

### ATK-10 — 并发价格设置（竞态条件）

**攻击**：同时发送两个带相同 `effective_from` 的 `POST /products/1/prices`。
**结论**：**EXPOSED**——没有包装 UPDATE + INSERT 的事务时，两个请求可能都通过 UPDATE 阶段并都 INSERT，创建两个开放层级。将 `setPrice` 包装在 `transactional()` 中。

---

### ATK-11 — 不存在的 product_id

**攻击**：为不存在的产品设置价格。
**结论**：**BLOCKED**——变更前的存在性检查。

---

### ATK-12 — 非数字路径 ID

**攻击**：传递非数字字符串作为 `{id}`。
**结论**：**在实践中被阻止**。注意：`(int) "9abc"` = `9`——ID 为 9 的产品会匹配。需要时使用 `ctype_digit()` 进行严格路径校验。

---

## ATK 汇总

| # | 攻击向量 | 裁定 |
|---|---|---|
| ATK-01 | 无认证 | EXPOSED（按设计） |
| ATK-02 | 回溯价格操纵 | EXPOSED |
| ATK-03 | 通过 `?datetime=` 的 SQL 注入 | BLOCKED |
| ATK-04 | 零金额价格 | 按设计接受 |
| ATK-05 | 负金额 | BLOCKED |
| ATK-06 | 货币代码注入（无白名单） | EXPOSED |
| ATK-07 | 超大金额 | BLOCKED |
| ATK-08 | 无效日期时间格式 | 部分 EXPOSED |
| ATK-09 | 未来的 `effective_from`（计划价格） | 按设计接受 |
| ATK-10 | 并发 setPrice 竞态条件 | EXPOSED |
| ATK-11 | 不存在的产品 | BLOCKED |
| ATK-12 | 非数字路径 ID | BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-01**——添加认证/授权
2. **ATK-02**——限制回溯仅限管理员调用者（或完全禁止）
3. **ATK-06**——针对 ISO 4217 白名单校验 `currency`
4. **ATK-08**——DB 查询前校验 `?datetime=` 格式
5. **ATK-10**——将 `setPrice` 的 UPDATE+INSERT 包装在事务中

---

## 相关指南

- [`expense-tracker.md`](expense-tracker.md) — `is_int()` 金额校验和 ISO 8601 日期往返
- [`habit-tracker.md`](habit-tracker.md) — ATK-01~12 模式（之前的 ATK 周期）
- [`prevent-double-booking.md`](prevent-double-booking.md) — 事务性读-检查-写
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — 严格 ISO 8601 校验
