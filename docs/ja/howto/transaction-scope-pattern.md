# ハウツー: トランザクションスコープパターン

> **FT リファレンス**: FT253 (`NENE2-FT/txlog`) — データベーストランザクション境界: アトミックな注文と在庫管理

NENE2 の `DatabaseTransactionManagerInterface::transactional()` の正しいパターンを実演します。リポジトリはトランザクションスコープの executor を使ってコールバック**内で**インスタンス化する必要があります — 事前に注入されたリポジトリは異なる接続で動作し、トランザクションが失敗しても書き込みは**ロールバックされません**。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/orders` | 注文を行う（アトミックな在庫減少 + 注文作成） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
```

`CHECK(stock >= 0)` はアプリケーションロジックにバグがあっても在庫がマイナスになるのを防ぐ DB レベルのセーフティネットです。アプリケーションも減少前に在庫を検証するため、通常の操作ではこの制約は発動しません。

---

## executor スコープのトラップ

`DatabaseTransactionManagerInterface::transactional()` はトランザクションを開き、コールバックに**トランザクションスコープの executor** を渡します。この executor を通じて行われた書き込みはトランザクションの一部であり、例外がスローされるとロールバックされます。

**❌ 間違い: トランザクション前に注入されたリポジトリ**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // 外部の executor を使用
        private readonly OrderRepository     $orders,    // 外部の executor を使用
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // バグ: $this->inventory と $this->orders は $txExecutor ではなく
            // 外部の executor を使用している。書き込みは異なる接続上にある。
            // トランザクションがロールバックされても、変更は元に戻らない。
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**✅ 正しい: コールバック内でリポジトリを作成する**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // トランザクションマネージャーのみを注入し、リポジトリは注入しない。
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // リポジトリはトランザクションスコープの executor でインスタンス化される。
            // すべての読み取りと書き込みが同じ接続、同じトランザクションを通じて行われる。
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // InsufficientStockException をスロー → ロールバックをトリガー
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

`transactional()` が渡すトランザクションスコープの `$executor` は同じ PDO 接続とトランザクションコンテキストを共有します。この executor を使ってコールバック内でリポジトリを作成することで、すべての書き込みが同じアトミックトランザクションに参加することが保証されます。

---

## アプリケーションレベルチェックによる在庫減少

```php
public function decrement(int $productId, int $qty): void
{
    $current = $this->getStock($productId);
    if ($current < $qty) {
        throw new InsufficientStockException($productId, $qty, $current);
    }
    $this->executor->execute(
        'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
        [$qty, $productId],
    );
}
```

読み取り後書き込みパターン（`getStock()` + `UPDATE`）はそれ自体ではアトミックではありません — 同時リクエストが同じ在庫を読み取り、両方が成功する可能性があります。本番使用には、これを `SELECT ... FOR UPDATE`（MySQL）でラップするか、楽観的チェックとして `UPDATE ... WHERE stock >= ?` を使用してください:

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- 次に影響行 == 1 を確認する; 0 なら InsufficientStockException をスロー
```

SQLite は `SELECT FOR UPDATE` をサポートしないため、`CHECK(stock >= 0)` 制約が DB レベルで同時競合をキャッチします — 2 番目の UPDATE が制約違反をスローし、例外として伝播してロールバックをトリガーします。

---

## 複数アイテムのアトミック性: 部分的な失敗が完全なロールバックをトリガーする

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // スローする可能性がある
}
return $orders->create($items); // すべての減少が成功した場合のみ到達
```

**最初**のアイテムが失敗した場合、在庫変更は行われません。**最後**のアイテムが失敗した場合、以前のすべての減少がロールバックされます。テストスイートは両方のケースを検証します:

```php
// Widget: 在庫 10; Gadget: 在庫 1。注文 [Widget×3, Gadget×5] は Gadget で失敗する。
$this->assertSame(10, $inventory->getStock(1)); // Widget は 10 に復元される
$this->assertSame(0, $orders->count());          // 注文は作成されない
```

---

## 例外 → ロールバック → 422 マッピング

`transactional()` 内でスローされた例外は呼び出し元に伝播します:

```php
try {
    $orderId = $this->service->placeOrder($items);
    return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
} catch (InsufficientStockException $e) {
    return $this->problems->create(
        $request,
        'insufficient-stock',
        'Insufficient stock.',
        422,
        $e->getMessage(),
    );
}
```

`transactional()` は例外をキャッチし、`ROLLBACK` を呼び出してから再スローします。呼び出し元は再スローされた例外をキャッチして Problem Details レスポンスにマッピングします。

---

## 入力バリデーション: 数量に対する厳密な `is_int`

```php
foreach ($body['items'] as $i => $item) {
    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity']) ||
        !is_int($item['product_id']) ||
        !is_int($item['quantity']) ||
        $item['quantity'] < 1
    ) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', ...]]],
        );
    }
}
```

`is_int()` は JSON 浮動小数点数（`1.0`）と文字列（`"3"`）を拒否します。PHP の JSON デコーダーは JSON 整数値を PHP の `int` に変換するため、`is_int()` は JSON 入力に対して正しく機能します。クエリ文字列パラメーター（常に文字列）には `is_numeric()` のみを使用してください。

---

## `transactional()` 使用サマリー

| やること | やってはいけないこと |
|---|---|
| コールバック内でリポジトリを作成する | クラス構築時にリポジトリを注入する |
| コールバックの `$executor` を新しいリポジトリに渡す | コンストラクターで注入された `$this->executor` を使用する |
| ロールバックをトリガーするために例外を伝播させる | コールバック内で例外をサイレントにキャッチする |
| コールバックから値を返す | 挿入された ID が必要なときに `void` を返す |

---

## 関連 howto

- [`transactions.md`](transactions.md) — `DatabaseTransactionManagerInterface` API リファレンス
- [`use-transactions.md`](use-transactions.md) — 既存のエンドポイントへのトランザクションの追加
- [`budget-tracking.md`](budget-tracking.md) — トランザクションを使った資金移動（2 アカウントの残高更新）
- [`order-management.md`](order-management.md) — 完全な注文ライフサイクル（作成、ステータス、キャンセル）
- [`optimistic-locking.md`](optimistic-locking.md) — トランザクションなしの競合状態防止
