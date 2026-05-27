# ハウツー: CQRS パターン

> **FT リファレンス**: FT233 (`NENE2-FT/cqrslog`) — CQRS パターン API

コマンドクエリ責任分離（CQRS）を実演します: 書き込み側はコマンドを受け取って書き込みモデルを変更し、読み取り側はクエリを受け取って非正規化された読み取りモデル（SQL VIEW）から読み取ります。両側は同じ SQLite データベースを共有しますが、別々のハンドラークラス、別々のモデルオブジェクト、共有状態なしで動作します。

---

## CQRS のコアコンセプト

| コンセプト | 説明 |
|---------|-------------|
| **コマンド** | 状態変更の意図 — `PlaceOrderCommand`、`UpdateOrderStatusCommand` |
| **クエリ** | データのリクエスト — `GetOrderSummaryQuery`、`ListOrderSummariesQuery` |
| **CommandHandler** | 書き込みモデル（正規化テーブル）に対してコマンドを実行する |
| **QueryHandler** | 読み取りモデル（非正規化ビュー）に対してクエリを実行する |
| **書き込みモデル** | トランザクション書き込みに最適化された正規化テーブル |
| **読み取りモデル** | クエリ出力形状に最適化された非正規化ビュー |

---

## ルート

| メソッド | パス | 側 | 説明 |
|---------|-----------------------|-------|--------------------------------|
| `POST`  | `/orders`             | 書き込み | 新しい注文を作成する（コマンド） |
| `PATCH` | `/orders/{id}/status` | 書き込み | 注文ステータスを更新する（コマンド） |
| `GET`   | `/orders`             | 読み取り | 注文サマリーを一覧表示する（クエリ） |
| `GET`   | `/orders/{id}`        | 読み取り | 1 件の注文サマリーを取得する（クエリ） |

---

## コマンドオブジェクト（書き込み側）

コマンドはバリデーション済みデータをハンドラーに運ぶイミュータブルな値オブジェクトです:

```php
final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {
    }
}

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int    $orderId,
        public string $newStatus,
    ) {
    }
}
```

コマンドはビジネスロジックを持ちません — コントローラーのバリデーション済み入力のための型付きコンテナです。`readonly` を使うことで構築後の変更を防止します。

---

## コマンドハンドラー（書き込みモデル）

`OrderCommandHandler` はすべての変更を所有します。正規化テーブルに書き込みます:

```php
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );

        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }

        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );

        return $affected > 0;
    }
}
```

ハンドラーはプリミティブ値（`int` の orderId、`bool` の成否）を返します — 読み取りモデルオブジェクトではありません。コマンドが成功した後、コントローラーはレスポンス形状を取得するために読み取り側を再クエリします。

---

## クエリオブジェクト（読み取り側）

クエリはクエリパラメーターの型付きラッパーです:

```php
final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId) {}
}

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status) {}
}
```

クエリパラメーターをオブジェクトにラップすることで、クエリハンドラーのコントラクトが明示的になり、ハンドラーシグネチャ全体のプリミティブ執着を避けられます。

---

## クエリハンドラー（読み取りモデル）

`OrderQueryHandler` は、DB 層で JOIN を非正規化する SQL VIEW `order_summary` から読み取ります:

```php
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_summary WHERE id = ?',
            [$query->orderId],
        );

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC',
                [],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id:         (int) $row['id'],
            customer:   (string) $row['customer'],
            status:     (string) $row['status'],
            createdAt:  (string) $row['created_at'],
            itemCount:  (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
```

`OrderSummary` は読み取りモデル DTO です — 書き込まれることはなく、クエリ結果のみを表します。書き込み側の `Order` エンティティから分離することで、読み取り側の関心が書き込みモデルに漏洩しません。

---

## 読み取りモデル: 非正規化プロジェクションとしての SQL VIEW

読み取りモデルは、DB 層で JOIN と集計を事前計算する SQLite `VIEW` です:

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id,
    o.customer,
    o.status,
    o.created_at,
    COUNT(oi.id)                     AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

ビューは安定したクエリサーフェスを提供します — クエリハンドラーは正規化された `orders`/`order_items` の JOIN を知る必要がありません。書き込みモデルのテーブル構造が変わっても、更新が必要なのはビュー定義だけで、クエリハンドラーは不要です。

`total_cents` は金額を整数セントで保存します（浮動小数点の丸め誤差なし）。`?? 0` はアイテムが存在しない場合の `NULL` を防ぎます。

---

## 書き込みモデルスキーマ

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product    TEXT    NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price INTEGER NOT NULL
);
```

書き込みモデルは正規化されています: `orders` + `order_items` が 1:N の関係。計算カラムなし — 読み取りプロジェクションはビューにあります。

---

## コントローラー: コマンドとクエリの接続

書き込みコマンドが成功した後、コントローラーはレスポンスを構築するために読み取り側を使用します:

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... 入力バリデーション ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // レスポンス形状を取得するために読み取りモデル経由で再クエリ
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

この「コマンドしてからクエリ」パターンにより、書き込み側はレスポンス形状を知らなくて済み、レスポンスが常にビューのプロジェクション（`total_cents` などの計算フィールドを含む）を反映することを保証します。

コマンド前のアイテムバリデーション:

```php
foreach ($rawItems as $item) {
    if (!is_array($item)) continue;

    $product   = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
    $quantity  = isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] > 0 ? $item['quantity'] : 0;
    $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) && $item['unit_price'] >= 0 ? $item['unit_price'] : -1;

    if ($product === '' || $quantity === 0 || $unitPrice < 0) {
        return $this->json->create(['error' => 'each item needs product, quantity>0, unit_price>=0'], 422);
    }
    $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
```

`quantity` と `unit_price` に対する `is_int()` 厳格チェックが JSON からの浮動小数点と文字列を拒否します。`unit_price >= 0` はゼロ（無料アイテム）を許可し、`quantity > 0` は最低 1 個を要求します。

---

## CQRS を使う場面

CQRS は構造的なオーバーヘッドを追加します。以下の場合に使用してください:

- 読み取りと書き込みのデータ形状が大幅に乖離している場合（例: 一覧は書き込みモデルが保存しない集計が必要）
- 読み取り負荷が書き込み負荷をはるかに上回り、独立してスケールしたい場合
- ドメインに複雑な書き込み不変条件（トランザクション、バリデーション、ドメインイベント）があり、読み取り最適化から切り離すべき場合
- イベントソーシングに向かっている場合（CQRS はイベントソースの書き込みモデルと自然にペアになる）

以下の場合は CQRS を避けてください:
- 読み取りと書き込みの形状が同一な場合（単純な CRUD エンドポイント）
- コードベースが小さく、間接参照が明確さのメリットを上回る場合
- チームがパターンに不慣れな場合（認知的オーバーヘッドを導入する）

---

## 関連ハウツー

- [`event-sourcing.md`](event-sourcing.md) — イベントストアに支えられた CQRS 書き込み側
- [`approval-workflow.md`](approval-workflow.md) — 注文ステータス遷移のステートマシン
- [`transactions.md`](transactions.md) — トランザクションでのコマンド書き込みのラップ
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — アイテムごとの結果を持つバルクコマンド
