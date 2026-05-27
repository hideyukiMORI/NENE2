# 如何防止 SQL ORDER BY 注入

SQL 的 `ORDER BY` 子句无法用标准占位符（`?`）进行参数化。这意味着用户控制的排序列和方向绝不能直接插入到 SQL 中。本指南说明唯一安全的方式：显式白名单。

---

## 问题所在

预处理语句占位符保护 `WHERE` 子句中的列值，但**不能**用于 `ORDER BY` 中的列名或排序方向：

```php
// ❌ 错误——这无法防止注入
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// 许多数据库驱动将 ORDER BY 参数视为字面量，而不是标识符。
```

发送 `?sort=SLEEP(5)` 或 `?sort=(SELECT password FROM users LIMIT 1)` 的攻击者可能造成基于时间的攻击、信息泄露，或揭示数据库架构细节的错误。

---

## 唯一安全方案：显式白名单

```php
// ✅ 安全——白名单 + in_array 严格模式
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

白名单值是你控制的**硬编码字符串**。只有这些值才会进入 SQL。

---

## 完整路由处理器模式

```php
// ── 排序列——必须根据白名单进行验证 ──────────────────────────────────
//
// 安全注意：ORDER BY 在标准 SQL 中不支持 ? 占位符。
// 唯一安全的方式是用 in_array 严格模式检查的显式白名单。
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // 数组注入：PSR-7 可能对 ?sort[]=id 给出数组
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // 空字节检查——PSR-7 将 %00 解码为实际空字节
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // 白名单检查——严格，大小写敏感。
    // PSR-7 已经将查询字符串 URL 解码一次（%65 → e），因此单次编码的有效列名
    // 可以通过。双重编码的值（%2565 → $rawSort 中的 %65）不会被第二次解码，
    // 因此白名单失败被拒绝。
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // 安全默认值
}

// ── 排序方向——仅白名单 ─────────────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order must be a string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order must be one of: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // 安全默认值
}
```

---

## Repository 层

Repository 接收已验证的值并直接插入：

```php
/**
 * $sortCol 和 $sortDir 必须由调用者经过白名单验证。
 * 此方法信任它们并直接将其插入到 SQL 中。
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol 和 $sortDir 已预先验证——可以安全插入。
    // 永远不要在这里放入原始用户输入。
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## 此方式阻断的攻击模式

| 攻击 | 输入 | 结果 |
|---|---|---|
| DROP TABLE 注入 | `?sort='; DROP TABLE articles--` | 422——不在白名单中 |
| UNION SELECT 数据提取 | `?sort=1; SELECT password` | 422——不在白名单中 |
| 子查询提取 | `?sort=(SELECT name FROM sqlite_master)` | 422——不在白名单中 |
| 基于时间的盲注 | `?sort=SLEEP(5)` | 422——不在白名单中 |
| 列索引注入 | `?sort=1` | 422——不在白名单中 |
| 未知列 | `?sort=password` | 422——不在白名单中 |
| 大小写/注释绕过 | `?sort=CREATED_AT--` | 422——大小写敏感 |
| 空字节绕过 | `?sort=created_at%00` | 422——空字节检查 |
| 数组注入 | `?sort[]=created_at` | 422——类型检查 |
| 双重 URL 编码 | `?sort=cr%2565ated_at` | 422——PSR-7 只解码一次；`cr%65ated_at` 不在白名单中 |
| 单次 URL 编码（有效） | `?sort=cr%65ated_at` | 200——PSR-7 解码为 `created_at` ✓ |
| 方向注入 | `?order=asc; UNION SELECT 1--` | 422——不在白名单中 |

---

## 关键要点

1. **PSR-7 之后不要 `rawurldecode()`**：PSR-7 的 `getQueryParams()` 已经将查询字符串解码一次。再次调用 `rawurldecode()` 会允许双重编码的值绕过白名单检查。

2. **`in_array($value, $allowlist, true)`**：第三个参数 `true` 启用严格（类型安全）比较。没有它，`in_array(0, ['id', 'created_at'])` 返回 `true`，因为 PHP 将字符串强制转换为整数。

3. **大小写敏感检查**：列名应该小写并精确匹配。白名单检查之前永远不要使用 `strcasecmp` 或 `strtolower`——从信任角度来看，`CREATED_AT` 和 `created_at` 不是同一个标记。

4. **方向：`strtolower(trim())` 是安全的**：与列名不同，方向（`asc`/`desc`）只有两个有效值。白名单检查前规范化大小写是可接受的，因为白名单本身是穷举且小写的。

5. **记录契约**：Repository 方法必须记录它信任其输入。调用者永远不能传递原始用户输入。

---

## 相关

- FT180 — sortlog：SQL ORDER BY 注入和动态排序/过滤防止
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — URI 编码
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
