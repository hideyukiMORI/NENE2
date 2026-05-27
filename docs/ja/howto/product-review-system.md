# 商品レビュー・評価システム

商品レビュー・評価システムを NENE2 で実装する方法。
1 ユーザー 1 商品 1 レビューの制約、評価集計（平均・分布）、CRUD を含みます。

---

## エンドポイント一覧

| Method | Path | 説明 | 認証 |
|---|---|---|---|
| `POST` | `/products/{productId}/reviews` | レビュー投稿 | 必須 |
| `GET` | `/products/{productId}/reviews` | レビュー一覧（カーソル） | 必須 |
| `GET` | `/products/{productId}/reviews/summary` | 平均評価・分布 | 必須 |
| `PUT` | `/products/{productId}/reviews/{reviewId}` | レビュー更新 | 本人のみ |
| `DELETE` | `/products/{productId}/reviews/{reviewId}` | レビュー削除 | 本人のみ |

---

## DB スキーマ

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

## 1 ユーザー 1 商品 1 レビューの強制

`UNIQUE(product_id, user_id)` 制約でデータ層から二重レビューを防ぎます。

アプリ層でも事前チェックし、409 Conflict を返します:

```php
if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
    return $this->json->create(['error' => 'already reviewed'], 409);
}
```

削除後は再投稿可能です（UNIQUE 制約が解除されるため）。

---

## 評価バリデーション

```php
// is_int() で浮動小数点数を拒否（JSON の 4.5 は float → 422）
if (!isset($body['rating']) || !is_int($body['rating'])) {
    $errors[] = new ValidationError('rating', 'Rating must be an integer.', 'required');
} elseif ($body['rating'] < 1 || $body['rating'] > 5) {
    $errors[] = new ValidationError('rating', 'Rating must be between 1 and 5.', 'out_of_range');
}
```

| 値 | 結果 |
|---|---|
| `5` (int) | 通過 |
| `4.5` (float) | 422 |
| `0` | 422 |
| `6` | 422 |
| 省略 | 422 |

---

## 評価集計（Summary）

```sql
-- 合計・平均
SELECT COUNT(*) as total, AVG(rating) as avg_rating
FROM reviews WHERE product_id = ?

-- 星ごとの分布
SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?
```

レスポンス例:

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

レビュー 0 件の場合は `"avg_rating": null`。

---

## 所有権チェック（更新・削除）

```php
$review = $this->repository->findReviewById($reviewId);
if ($review === null || (int) $review['product_id'] !== $productId) {
    return $this->json->create(['error' => 'review not found'], 404);
}
if ((int) $review['user_id'] !== $userId) {
    return $this->json->create(['error' => 'forbidden'], 403);
}
```

`product_id` のクロスチェックにより、他商品のレビュー ID を指定した場合も 404 を返します。

---

## カーソルページネーション

```
GET /products/1/reviews?limit=20
→ { items: [...], next_cursor: 42 }

GET /products/1/reviews?limit=20&before_id=42
→ { items: [...], next_cursor: null }
```

`next_cursor: null` で末尾到達。

---

## 実装サンプル（FT154）

`/home/xi/docker/NENE2-FT/reviewlog/`
