# NENE2 でゲスト注文システム（カート → 注文 → 注文明細）を構築する方法

このガイドでは、ユーザーがカートに商品を追加し、在庫を確認し、注文明細に価格スナップショットを記録する注文を行う e コマース注文フローを構築する手順を解説します。

**フィールドトライアル**: FT139
**NENE2 バージョン**: ^1.5
**対象トピック**: マルチテーブル結合、在庫バリデーション、order_items での価格スナップショット、カートの分離、`array_sum` による合計計算

---

## 構築するもの

- `POST /products` — 商品を作成する（name、price、stock）
- `POST /cart` — カートに商品を追加する（既にある場合は数量を累積）
- `GET /cart` — カートの内容と合計を表示する（X-User-Id でユーザーを識別）
- `DELETE /cart/{productId}` — カートからアイテムを削除する
- `POST /orders` — 注文を行う（在庫を確認し、在庫を減らし、カートをクリア）
- `GET /orders/{orderId}` — 注文詳細とアイテムを表示する（オーナーのみ）

---

## データベーススキーマ

```sql
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 1,
    added_at   TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    total      INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

`cart_items` の `UNIQUE (user_id, product_id)` は重複行を防止します — 同じ商品を再度追加すると数量が累積されます。

---

## order_items での価格スナップショット

注文が行われると、現在の商品の `name` と `price` が `order_items` にコピーされます。これにより、過去の注文が将来の価格変更から保護されます。

```php
/** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
public function createOrder(int $userId, array $items, int $total, string $now): int
{
    $this->executor->execute(
        'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
        [$userId, $total, $now],
    );

    $orderId = (int) $this->executor->lastInsertId();

    foreach ($items as $item) {
        $this->executor->execute(
            'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
        );
    }

    return $orderId;
}
```

---

## カートの数量累積

`UNIQUE (user_id, product_id)` により、同じ商品に対する 2 回目の `POST /cart` は INSERT ではなく UPDATE しなければなりません:

```php
public function addToCart(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->findCartItem($userId, $productId);

    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $userId, $productId],
        );
        return;
    }

    $this->executor->execute(
        'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
        [$userId, $productId, $quantity, $now],
    );
}
```

---

## 注文前の在庫バリデーション

在庫を減らす前にすべてのアイテムを確認してください。部分的な在庫デクリメントのロールバックは複雑です — 先にバリデーションし、その後実行してください:

```php
// すべてのアイテムをバリデーション
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// 在庫を減らして注文を作成
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## カート合計計算

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

これは SQL ではなく、結合クエリ結果から PHP で計算されます。カートプレビューと保存された注文合計の両方に同じ計算を使用します。

---

## ユーザーごとのカート分離

カートアイテムは常に `user_id` でフィルタリングされます。各ユーザーは自分自身のカートのみを表示・変更できます。`GET /cart` ハンドラーはアイテムのないユーザーに対して空のリストを返します — 他のユーザーのカートは返しません。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| 同じ商品を 2 回追加すると重複行が作成される | `UNIQUE (user_id, product_id)` + 競合時に UPDATE |
| 注文後の価格変更で履歴が壊れる | 注文時に `name` と `price` を `order_items` にコピーする |
| 複数アイテム失敗時の部分的な在庫デクリメント | すべてのアイテムを先にバリデーションしてから全件デクリメントする |
| 注文詳細でライブの商品価格を返す | `products.price` ではなく `order_items.price` をクエリする |
| ユーザー間でカートが見える | 常に `user_id` で `cart_items` をフィルタリングする |
