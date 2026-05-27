# 愿望清单管理

带优先级和备注的愿望清单实现指南。
涵盖存在隐藏模式、幂等添加和多路径参数。

## 概述

- 用户创建命名愿望清单（公开/私有）
- 每个愿望清单可以添加商品（`priority`：high/medium/low，可选 `note`）
- 私有愿望清单对非所有者返回 404（存在隐藏模式）
- 商品添加是幂等的（已存在返回 200，新增返回 201）
- 无顺序管理（无位置管理）— 与内容集合（FT149）的主要区别

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/wishlists` | 创建愿望清单 |
| `GET` | `/wishlists/{id}` | 获取愿望清单（公开或自己的） |
| `PUT` | `/wishlists/{id}` | 修改名称和公开设置 |
| `DELETE` | `/wishlists/{id}` | 删除愿望清单 |
| `POST` | `/wishlists/{id}/items` | 添加商品（幂等） |
| `DELETE` | `/wishlists/{id}/items/{productId}` | 删除商品 |

## 数据库设计

```sql
CREATE TABLE wishlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE wishlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wishlist_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    note TEXT,
    added_at TEXT NOT NULL,
    UNIQUE (wishlist_id, product_id),
    CHECK (priority IN ('high', 'medium', 'low')),
    FOREIGN KEY (wishlist_id) REFERENCES wishlists(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

与内容集合（FT149）不同，没有 `position` 列。
`UNIQUE (wishlist_id, product_id)` 是幂等添加的 DB 层防御。

## 存在隐藏模式

```php
$isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
$isPublic = (bool) $wishlist['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
}
```

只有 GET 返回 404。PUT/DELETE/POST items 返回 403，向所有者明示权限不足。

## 幂等商品添加

```php
$existing = $this->repository->findItem($id, $productId);
if ($existing !== null) {
    return $this->responseFactory->create([
        'message' => 'already in wishlist',
        'product_id' => $productId,
        'priority' => $existing['priority'],
        'note' => $existing['note'],
    ], 200);
}
$now = date('c');
$this->repository->addItem($id, $productId, $priority, $note, $now);
return $this->responseFactory->create([...], 201);
```

## priority 验证（无效值回退为默认值）

```php
private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

$priority = isset($body['priority']) && is_string($body['priority'])
    && in_array($body['priority'], self::VALID_PRIORITIES, true)
    ? $body['priority']
    : 'medium';
```

无效的 priority 值不报错，而是回退为 `'medium'`。
客户端可以安全地发送未知的 priority 值（前向兼容性）。

## GET /wishlists/{id} 响应示例

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Birthday Wishlist",
  "is_public": true,
  "item_count": 2,
  "items": [
    {
      "product_id": 3,
      "product_name": "Wireless Headphones",
      "priority": "high",
      "note": "Black color preferred",
      "added_at": "2026-05-21T..."
    },
    {
      "product_id": 1,
      "product_name": "Coffee Mug",
      "priority": "low",
      "note": null,
      "added_at": "2026-05-21T..."
    }
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## 集合与愿望清单的区别

| 维度 | 集合（FT149） | 愿望清单（FT151） |
|---|---|---|
| 顺序 | 有位置管理 | 无（按添加顺序） |
| 商品元数据 | 无 | priority + note |
| 上限 | 50 件 | 无 |
| 使用场景 | 读书列表、策划集合 | 心愿单、礼品登记 |

## 所有权检查模式

```php
if ((int) $wishlist['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

PUT/DELETE/POST items 全部使用同一模式。
