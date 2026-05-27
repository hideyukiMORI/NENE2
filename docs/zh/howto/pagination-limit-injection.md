# 操作指南：分页边界与 limit 注入防护

> **FT 参考**：FT319（`NENE2-FT/limitlog`）——偏移量和游标分页，带严格的 limit/page 校验、MAX_LIMIT 上限强制、ReDoS 安全的 ctype_digit 校验，20 个测试 / 384 个断言全部通过。

本指南展示如何同时使用偏移量和游标策略实现安全分页，同时防止整数边界攻击和 limit 注入。

## 常量

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## 偏移量分页

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 条记录
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// 25 条记录 limit=10 的第 3 页 → 最后一页
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 条记录
```

**OFFSET 计算**：`(page - 1) * limit`——page 必须 ≥ 1 以防止负 OFFSET。

## 游标分页

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

游标是最后一条记录的 `id`：`WHERE id < $after ORDER BY id DESC LIMIT $limit`。

## 作者筛选

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // 只有 author_id = 2 的记录
```

`author_id` 必须是正整数（与 `limit` 使用相同的校验）。

## limit 校验——`ctype_digit` 模式

使用 `ctype_digit()` 进行 O(n) 校验——与正则 `^\d+$` 不同，免疫 ReDoS：

```php
/**
 * 解析查询字符串整数参数。
 * 拒绝：零、负数、浮点数、溢出、非数字、空白字符。
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // 拒绝空字符串、浮点数、符号、空白字符、非数字字符
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // 在转换之前防止 64 位溢出
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### `ctype_digit` 能阻止什么

| 输入 | `ctype_digit` | 原因 |
|-------|--------------|-----|
| `"10"` | ✅ 通过 | 有效数字 |
| `"0"` | ✅ 通过（ctype） | 被 min=1 检查拒绝 |
| `"-1"` | ❌ 拒绝 | `-` 不是数字 |
| `"10.5"` | ❌ 拒绝 | `.` 不是数字 |
| `"1e2"` | ❌ 拒绝 | `e` 不是数字 |
| `"+10"` | ❌ 拒绝 | `+` 不是数字 |
| `" 10"` | ❌ 拒绝 | 空格不是数字 |
| `"0x10"` | ❌ 拒绝 | `x` 不是数字 |
| `"10\x00"` | ❌ 拒绝 | 空字节不是数字 |
| 20 位字符串 | ❌ 拒绝 | strlen > 18 守护 |
| ReDoS 载荷 `"1...1x"` | ❌ 快速拒绝 | O(n) 扫描，无回溯 |

### 错误情况

```php
GET /articles?limit=999999  → 422  // 超过 MAX_LIMIT
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // 不满足 ctype_digit
GET /articles?limit=10.5    → 422  // 浮点数
GET /articles?limit=abc     → 422  // 非数字
GET /articles?page=0        → 422  // 负 OFFSET
GET /articles/cursor?after=99999999999999999999  → 422  // 溢出
```

## 重复参数攻击

```php
GET /articles?limit=5&limit=1000
// PHP 取最后一个值：1000 → 超过 MAX_LIMIT → 422
```

大多数 PSR-7 实现取最后一次出现的值。422（最后一个值超过最大值）或带有效值的 200 都是可以接受的——永不静默使用 1000。

## 超大页码

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // 空结果，不崩溃
```

超过总记录数的超大页码是有效的——返回空数据，而非报错。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 未使用 `ctype_digit` 直接执行 `(int) $raw` | `-1`、`1.5`、`" 10"` 全都静默转换为整数 |
| 使用正则 `/^\d+$/` 进行整数校验 | 对长混合输入造成灾难性回溯（ReDoS） |
| 无 MAX_LIMIT 上限 | `limit=999999` 一次请求转储整张表 |
| 允许 `page=0` | `OFFSET = (0-1)*limit = -limit` 破坏或报错 SQL 查询 |
| 仅用 strlen 溢出守护 | `"1.5"` 只有 3 个字符——足够短以通过，但不是有效整数 |
| 不检查 `author_id` 的最小值 | `author_id=0` 静默返回空结果；语义上无效 |
