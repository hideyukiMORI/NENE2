# ハウツー: ネスト JSON バリデーション

> **FT リファレンス**: FT322 (`NENE2-FT/nestedjsonlog`) — ネストされたリクエストボディの深いバリデーション

ネストされたアイテム配列を持つ注文 API を通じて、深い JSON バリデーションを実証します。`items.N.field` 形式のエラーパスを使い、1 回のレスポンスで**すべての**バリデーションエラーを収集して返します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/orders` | ネストされたアイテムを含む注文を作成する |
| `GET` | `/orders/{id}` | 注文とアイテムを取得する |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer    TEXT    NOT NULL,
    total_cents INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id  INTEGER NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_price  INTEGER NOT NULL,  -- セント単位
    line_total  INTEGER NOT NULL   -- quantity * unit_price
);
```

---

## エラーパス: `items.N.field`

ネストされた配列のバリデーションエラーは `items.N.field` 形式のパスを使います:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "errors": [
    { "field": "customer",         "message": "customer は必須です" },
    { "field": "items",            "message": "items は空にできません" },
    { "field": "items.0.product_id", "message": "product_id は 1 以上の整数でなければなりません" },
    { "field": "items.1.quantity", "message": "quantity は 1 以上の整数でなければなりません" },
    { "field": "items.2.unit_price", "message": "unit_price は 0 より大きい数でなければなりません" }
  ]
}
```

`items.0.product_id` の形式はネストの深さを明示します — フロントエンドはどのフィールドを赤くするかを正確に把握できます。

---

## OrderValidator: すべてのエラーを 1 回で収集する

```php
final class OrderValidator
{
    /** @return list<array{field: string, message: string}> */
    public function validate(mixed $body): array
    {
        $errors = [];

        if (!is_array($body)) {
            $errors[] = ['field' => 'body', 'message' => 'リクエストボディは JSON オブジェクトでなければなりません'];
            return $errors;
        }

        // customer のバリデーション
        $customer = $body['customer'] ?? null;
        if ($customer === null || $customer === '') {
            $errors[] = ['field' => 'customer', 'message' => 'customer は必須です'];
        } elseif (!is_string($customer)) {
            $errors[] = ['field' => 'customer', 'message' => 'customer は文字列でなければなりません'];
        } elseif (strlen($customer) > 200) {
            $errors[] = ['field' => 'customer', 'message' => 'customer は 200 文字以内でなければなりません'];
        }

        // items のバリデーション
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = ['field' => 'items', 'message' => 'items は空にできません'];
        } else {
            foreach ($items as $i => $item) {
                $errors = [...$errors, ...$this->validateItem($item, $i)];
            }
        }

        return $errors;
    }

    /** @return list<array{field: string, message: string}> */
    private function validateItem(mixed $item, int $index): array
    {
        $errors = [];
        $prefix = "items.{$index}";

        if (!is_array($item)) {
            $errors[] = ['field' => $prefix, 'message' => 'アイテムは JSON オブジェクトでなければなりません'];
            return $errors;
        }

        // product_id: 1 以上の整数
        $productId = $item['product_id'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            $errors[] = [
                'field'   => "{$prefix}.product_id",
                'message' => 'product_id は 1 以上の整数でなければなりません',
            ];
        }

        // quantity: 1 以上の整数
        $quantity = $item['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1) {
            $errors[] = [
                'field'   => "{$prefix}.quantity",
                'message' => 'quantity は 1 以上の整数でなければなりません',
            ];
        }

        // unit_price: 0 より大きい数値
        $unitPrice = $item['unit_price'] ?? null;
        if ((!is_int($unitPrice) && !is_float($unitPrice)) || $unitPrice <= 0) {
            $errors[] = [
                'field'   => "{$prefix}.unit_price",
                'message' => 'unit_price は 0 より大きい数でなければなりません',
            ];
        }

        return $errors;
    }
}
```

**なぜ 1 回のレスポンスですべてのエラーを返すのか**: エラーを 1 件ずつ返すと、フロントエンドは「送信 → 修正 → 再送信」を繰り返すことになります。すべてのエラーを一度に収集することで、ユーザーは 1 回の修正ですべての問題に対処できます。

---

## ハンドラーでのバリデーション

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $body   = $request->getParsedBody();
    $errors = $this->validator->validate($body);

    if ($errors !== []) {
        return $this->problems->validationFailed($request, $errors);
    }

    /** @var array{customer: string, items: list<array{product_id: int, quantity: int, unit_price: int|float}>} $body */
    $order = $this->useCase->create(
        customer: $body['customer'],
        items:    $body['items'],
    );

    return $this->json->create($order->toArray(), 201);
}
```

---

## トランザクション内での注文作成

```php
public function create(string $customer, array $items): Order
{
    $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    return $this->txManager->transactional(
        function (DatabaseQueryExecutorInterface $tx) use ($customer, $items, $now): Order {
            // 合計をセント単位で計算
            $totalCents = 0;
            foreach ($items as $item) {
                $totalCents += (int) round($item['unit_price'] * $item['quantity']);
            }

            $orderId = $tx->insert(
                'INSERT INTO orders (customer, total_cents, created_at) VALUES (?, ?, ?)',
                [$customer, $totalCents, $now],
            );

            $orderItems = [];
            foreach ($items as $item) {
                $lineTotal = (int) round($item['unit_price'] * $item['quantity']);
                $itemId    = $tx->insert(
                    'INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?)',
                    [$orderId, $item['product_id'], $item['quantity'], (int) round($item['unit_price']), $lineTotal],
                );
                $orderItems[] = new OrderItem($itemId, $item['product_id'], $item['quantity'], $lineTotal);
            }

            return new Order($orderId, $customer, $totalCents, $orderItems, $now);
        }
    );
}
```

---

## バリデーションルール一覧

| フィールド | ルール |
|------------|--------|
| `customer` | 必須、文字列、最大 200 文字 |
| `items` | 必須、空でない配列 |
| `items[].product_id` | 整数、1 以上 |
| `items[].quantity` | 整数、1 以上 |
| `items[].unit_price` | 数値（int または float）、0 より大きい |

---

## 設計上の決定

- **`items.N.field` パス形式**: ゼロ始まりのインデックスを使用します。`items[0].product_id` ではなく `items.0.product_id` — 多くのフロントエンドバリデーションライブラリのドット記法に合わせています。
- **すべてのエラーを収集**: 最初のエラーで即座に `return` しません。ネストされたループでも同様にすべてのエラーを蓄積します。
- **型チェックを先に**: `$productId < 1` をチェックする前に `is_int($productId)` を確認します。PHP では `null < 1` が `true` になる可能性があるためです。
- **price は整数で保存**: `unit_price` はセント単位の整数として DB に保存します。`float` の丸め誤差を避けるためです。

---

## 関連 howto

- [`money-integer-arithmetic.md`](money-integer-arithmetic.md) — 通貨の整数演算と丸めポリシー
- [`validation.md`](validation.md) — フラットなリクエストバリデーション基本パターン
- [`multi-step-workflow.md`](multi-step-workflow.md) — 複数ステップの状態管理
