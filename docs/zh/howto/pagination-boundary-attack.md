# 操作指南：分页边界与 limit 注入

**FT177 — limitlog**

针对基于偏移量和游标的分页进行无懈可击的整数参数校验——防止 DB 转储、溢出、类型混淆和 ReDoS。

---

## 攻击面

每个分页端点都至少暴露两个整数参数（`limit`、`page` / `after`）。攻击者常规性地用以下方式进行探测：

| 攻击 | 示例 | 风险 |
|--------|---------|------|
| 超大 limit | `limit=999999` | 全表转储 |
| 零/负数 | `limit=0`、`limit=-1` | 负 OFFSET → DB 报错或回绕 |
| 浮点数注入 | `limit=10.5`、`limit=1e2` | 静默转换：`(int)"10.5" === 10` |
| 填充/带符号 | `limit=+10`、`limit= 10` | 静默裁剪：`(int)" 10" === 10` |
| 整数溢出 | `limit=99999999999999999999` | 64 位回绕为负数 |
| 非数字 | `limit=abc`、`limit=1;DROP TABLE` | 类型错误或注入 |
| 十六进制/八进制 | `limit=0x10`、`limit=010` | `0x` → ctype 失败；`010` 通过！ |
| 重复参数 | `?limit=5&limit=1000` | 最后一个值遮蔽已校验的值 |
| ReDoS 载荷 | `limit=111...1x` | 指数级正则回溯 |

---

## `clampInt()` 模式

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // 缺失 → 使用默认值（不是 null = 无效）
    }

    $raw = $params[$key];

    // ctype_digit: O(n)，免疫 ReDoS，拒绝 '' / '-' / '.' / '+' / ' ' / 'e'
    // ctype_digit('') === false  →  空字符串已被拒绝
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // 信号：调用者必须返回 422
    }

    // 防止 PHP 静默溢出：(int)"99999999999999999999" 会回绕
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### 为何使用 `ctype_digit` 而非正则

| 校验器 | ReDoS 安全？ | 拒绝 `010`？ | 拒绝 `+10`？ |
|-----------|------------|----------------|----------------|
| `/^\d+$/` | ❌ `111...1x` 上指数级 | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅（`0` 前缀：通过——但被范围限制） | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌（`+10` 通过！） |

**使用 `ctype_digit()`**——最严格且最快。

### `010` 的陷阱

`ctype_digit('010')` → `true`（通过数字检查），`(int)'010'` → `10`（十进制，而非八进制）。
这是安全的，因为 PHP 对字符串转换的整数不执行八进制解释（与 PHP 字面量 `010` 不同）。如果团队不确定，请在测试中确认。

---

## 游标分页

```php
// 多取一行以确定 has_more——无需 COUNT 查询
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // 丢弃哨兵行
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### 游标的"首页"哨兵

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor（无 ?after 参数）→ afterId 默认为 PHP_INT_MAX
// WHERE id < PHP_INT_MAX  ==>  实际上包含所有行
```

---

## 偏移量分页——第零页守护

`page=0` 产生 `OFFSET = (0-1) * limit = -limit`——负 OFFSET 在某些数据库中是 SQL 错误（MySQL 拒绝）或在其他数据库中静默回绕。

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 返回 null → 422
```

---

## 整数溢出守护

PHP 的 `(int)` 转换对 20 位字符串会静默回绕：

```php
(int)'99999999999999999999'  // 在 64 位 PHP 上 === -1
```

`strlen($raw) > 18` 守护在转换之前阻止这种情况。18 位数字安全覆盖 `PHP_INT_MAX`（19 位），留有余量使转换始终安全。

---

## VULN-A 到 VULN-L 检查清单

| # | 测试 | 预期 |
|---|------|-------------|
| VULN-A | `limit` 超过最大值（100） | 422——显式拒绝，而非静默截断 |
| VULN-B | `limit=0`、`limit=-1` | 422——`0` 不满足 min=1；`-` 不满足 ctype_digit |
| VULN-C | 浮点字符串 `10.5`、`1e2`、`1.0` | 422——`.` 和 `e` 不满足 ctype_digit |
| VULN-D | 填充 `%2010`、`10%20`、`%2B10` | 422——空格/`+` 不满足 ctype_digit |
| VULN-E | 溢出 `9999...`（20 位） | 422——strlen > 18 守护 |
| VULN-F | 非数字、十六进制 `0x10`、SQL 注入 | 422——ctype_digit 全部拒绝 |
| VULN-G | `page=0`（偏移量分页） | 422——min=1 守护 |
| VULN-H | 游标边界：`after=0` 有效，溢出游标 422 | 混合 |
| VULN-I | `author_id=0`、`-1`、`abc`、`1.5` | 422 |
| VULN-J | 超大页码（page=999999） | 200 空结果——不得崩溃 |
| VULN-K | 重复参数 `?limit=5&limit=1000` | 200（安全）或 422——永不超过最大值 |
| VULN-L | ReDoS 载荷 `111...1x`（50 位 + x） | 422，耗时 < 100ms |

---

## 测试说明：VULN-J 与 VULN-A

这两者看起来矛盾，但服务于不同目标：

- **VULN-A**：`limit=999999` → **422**——拒绝不合理的大行数
- **VULN-J**：`page=999999&limit=10` → **200 空结果**——语义上有效但实际为空的页面

服务端不得在语义上有效但实际为空的页面上崩溃或报错。
`OFFSET = (999999-1) * 10 = 9999980` 是合法的 SQL OFFSET；结果只是空的。
