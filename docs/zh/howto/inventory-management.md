# 操作指南：库存管理 API

本指南展示如何使用 NENE2 构建带库存调整和历史记录追踪功能的库存管理 API。
模式由 **inventorylog** 字段试验（FT220，ATK 破解者攻击测试）演示。

## 功能特性

- 创建带 SKU、名称、价格和初始数量的库存商品（仅管理员）
- 获取商品详情（公开）
- 以带符号的增量调整库存（正数 = 补货，负数 = 消耗）
- 库存不足检测 → 409 Conflict
- 完整调整历史日志

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/items` | 管理员 | 创建库存商品 |
| `GET` | `/items/{id}` | 公开 | 获取商品及当前库存 |
| `POST` | `/items/{id}/adjust` | 管理员 | 调整库存（增量 ± N） |
| `GET` | `/items/{id}/history` | 公开 | 获取调整历史 |

## 库存调整模式

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // 原子性更新 + 日志
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## 增量校验

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## ATK 破解者测试结果（FT220）

- **ATK-01**：SKU 中的 SQL 注入 → 被 `/\A[A-Z0-9\-]{1,32}\z/` 模式拦截（422）
- **ATK-01**：路径 ID 中的 SQL 注入 → 被 `ctype_digit()` 拦截（404）
- **ATK-02**：`price_cents` 整数溢出 → 浮点数被 `is_int()` 拒绝（422）
- **ATK-03**：超大路径 ID → `strlen > 18` 防护（404）
- **ATK-04**：耗尽到零边界 → 允许（数量 = 0 是有效的）
- **ATK-05**：超大 `quantity`（> 1,000,000）→ 被拒绝（422）
- **ATK-06**：错误/空管理员密钥 → 403（故障关闭）
- **ATK-09**：过度消耗攻击 → `insufficient_stock` → 409，库存不变
- **ATK-10**：浮点数 `delta` → 被 `is_int()` 拒绝（422）
- **ATK-11**：无请求体的请求 → 400（需要 JSON 请求体）
- **ATK-12**：错误响应不包含 SQLSTATE / 堆栈跟踪 / 内部路径

## 安全模式

- **管理员故障关闭**：在 `hash_equals()` 之前执行 `if ($this->adminKey === '') return false;`
- **`is_int()` 严格检查**：price_cents、quantity、delta——拒绝来自 JSON 的浮点数
- **`ctype_digit()`**：路径 ID 的 ReDoS 安全整数校验
- **SKU 模式**：`/\A[A-Z0-9\-]{1,32}\z/` 拦截 SQL 注入尝试
- **原子性操作**：更新 + 日志插入按顺序执行（在单个连接内）
