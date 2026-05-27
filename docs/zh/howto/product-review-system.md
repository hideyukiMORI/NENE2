# 商品评价与评分系统

在 NENE2 中实现商品评价与评分系统的方法。包含每用户每商品限一条评价的约束、评分聚合（平均值与分布），以及完整的 CRUD 操作。

---

## 端点列表

| 方法 | 路径 | 说明 | 认证 |
|---|---|---|---|
| `POST` | `/products/{productId}/reviews` | 提交评价 | 必须 |
| `GET` | `/products/{productId}/reviews` | 评价列表（游标分页） | 必须 |
| `GET` | `/products/{productId}/reviews/summary` | 平均评分与分布 | 必须 |
| `PUT` | `/products/{productId}/reviews/{reviewId}` | 更新评价 | 仅本人 |
| `DELETE` | `/products/{productId}/reviews/{reviewId}` | 删除评价 | 仅本人 |

---

## 数据库结构

```sql
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
    body TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (product_id, user_id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## 每用户每商品限一条评价

通过 `UNIQUE(product_id, user_id)` 约束从数据层防止重复评价。

应用层也进行预检查，并返回 409 Conflict：

```php
if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
    return $this->json->create(['error' => 'already reviewed'], 409);
}
```

删除后可重新提交评价（UNIQUE 约束随之解除）。

---

## 评分校验

```php
// is_int() 拒绝浮点数（JSON 中的 4.5 为 float → 422）
if (!isset($body['rating']) || !is_int($body['rating'])) {
    $errors[] = new ValidationError('rating', 'Rating must be an integer.', 'required');
} elseif ($body['rating'] < 1 || $body['rating'] > 5) {
    $errors[] = new ValidationError('rating', 'Rating must be between 1 and 5.', 'out_of_range');
}
```

| 值 | 结果 |
|---|---|
| `5`（int） | 通过 |
| `4.5`（float） | 422 |
| `0` | 422 |
| `6` | 422 |
| 省略 | 422 |

---

## 评分聚合（Summary）

```sql
-- 总计与平均值
SELECT COUNT(*) as total, AVG(rating) as avg_rating
FROM reviews WHERE product_id = ?

-- 按星级分布
SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?
```

响应示例：

```json
GET /products/1/reviews/summary

{
    "total": 150,
    "avg_rating": 4.23,
    "distribution": {
        "1": 5,
        "2": 8,
        "3": 20,
        "4": 52,
        "5": 65
    }
}
```

评价数为 0 时，`"avg_rating": null`。

---

## 所有权检查（更新与删除）

```php
$review = $this->repository->findReviewById($reviewId);
if ($review === null || (int) $review['product_id'] !== $productId) {
    return $this->json->create(['error' => 'review not found'], 404);
}
if ((int) $review['user_id'] !== $userId) {
    return $this->json->create(['error' => 'forbidden'], 403);
}
```

通过 `product_id` 交叉校验，当指定其他商品的评价 ID 时也返回 404。

---

## 游标分页

```
GET /products/1/reviews?limit=20
→ { items: [...], next_cursor: 42 }

GET /products/1/reviews?limit=20&before_id=42
→ { items: [...], next_cursor: null }
```

`next_cursor: null` 表示已到达末尾。

---

## 实现示例（FT154）

`/home/xi/docker/NENE2-FT/reviewlog/`
