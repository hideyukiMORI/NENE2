# 操作指南：库存管理

## 概述

本指南介绍如何使用 NENE2 构建库存管理 API。功能包括基于 SKU 的商品注册、入库/出库操作、负库存防护以及事务历史记录。

**参考实现**：`../NENE2-FT/inventorylog/`

---

## 数据库结构设计

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## 路由表

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/inventory/items` | 注册商品（SKU + 名称） |
| `GET` | `/inventory/items` | 列出所有商品 |
| `GET` | `/inventory/items/{id}` | 按 ID 获取商品 |
| `POST` | `/inventory/items/{id}/in` | 入库（收货） |
| `POST` | `/inventory/items/{id}/out` | 出库（发货） |
| `GET` | `/inventory/items/{id}/history` | 事务历史 |

---

## SKU 校验

限制 SKU 格式以防止注入并确保规范形式：

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## 库存操作

### 入库

始终安全——直接增加：

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### 出库（含库存不足防护）

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## 数量校验

拒绝非整数和非正数数量：

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

这能同时捕获 `"50"`（字符串）和 `-1`（负数）。

---

## HTTP 状态码

| 情况 | 状态码 |
|------|--------|
| 商品已创建 | 201 |
| 库存已增加/减少 | 200 |
| 找到商品/历史记录 | 200 |
| 字段缺失或为空 | 422 |
| SKU 格式无效 | 422 |
| 数量为非整数或负数 | 422 |
| 商品不存在 | 404 |
| SKU 重复 | 409 |
| 库存不足 | 409 |

---

## 注意事项

- **原子性更新**：在 SQL 中使用 `stock = stock + :qty` 和 `stock = stock - :qty`，即使在并发访问下也能保持余额一致。
- **审计追踪**：每次库存变更都向 `stock_history` 写入一行记录以供追溯。
- **软约束**：应用程序在减少库存前检查余量。对于并发场景的严格正确性，可在 DB 中添加 `CHECK (stock >= 0)` 列约束，或使用带行锁的事务。
