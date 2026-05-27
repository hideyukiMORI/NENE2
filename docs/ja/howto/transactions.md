# データベーストランザクション

アトミックな複数ステップ操作には `DatabaseTransactionManagerInterface::transactional()` を使用してください。
いずれかのステップがスローした場合、コールバック内のすべての変更は自動的にロールバックされます。

## 重要なルール: コールバック内でリポジトリをインスタンス化する

> **警告:** 構築時に注入されたリポジトリは*異なる* PDO 接続を使用し、**トランザクションの外で**実行されます。ロールバックはそれらの変更を元に戻しません。

`PdoConnectionFactory` は呼び出されるたびに新しい接続を作成します。`transactional()` がトランザクションを開くとき、*新しい*接続でそれを開きます。この呼び出しの前に作成されたリポジトリ（例: コンストラクター DI で注入されたもの）は異なる接続を保持しています — トランザクションの一部ではない接続です。

### 正しいパターン

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // ✅ tx スコープの executor でコールバック内でリポジトリをインスタンス化する
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // InsufficientStockException をスロー → ロールバックが自動的にトリガーされる
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### 間違ったパターン（変更がロールバックされない）

```php
// ❌ これらのリポジトリは異なる接続を保持 — トランザクションの一部でない
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← 異なる接続、トランザクションの外!
        }
        return $this->orders->create($items); // ← 同じ問題
        // 例外がスローされると、$this->inventory の変更は元に戻らない
    });
}
```

これはサイレントな失敗です: コードはコンパイルされ、PHPStan は検出できず、テストはロールバック動作を具体的に検証しない限り通過する場合があります。

---

## ロールバック動作

`transactional()` は `Throwable` をキャッチし、ロールバックしてから再スローします。呼び出し元は元の例外を受け取ります。

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // この呼び出しからのすべての在庫減少が元に戻される
    // 注文は作成されていない
}
```

---

## 事前バリデーション + アトミック操作パターン

上記のフェイルファストパターンは最初の在庫切れアイテムで停止し、クライアントは他の失敗を知ることができません。
UX が重要な場合は、最初にすべてのエラーを収集してからアトミックに実行してください:

```php
public function placeOrder(array $items): int
{
    // フェーズ 1: トランザクションの外ですべてのアイテムを検証する（読み取り専用）
    $errors = [];
    foreach ($items as $item) {
        $stock = $this->getStockSnapshot($item['product_id']);
        if ($stock < $item['quantity']) {
            $errors[] = [
                'product_id' => $item['product_id'],
                'requested'  => $item['quantity'],
                'available'  => $stock,
            ];
        }
    }

    if ($errors !== []) {
        throw new InsufficientStockException($errors);
    }

    // フェーズ 2: アトミックな減少 — 内部では tx スコープの executor を使用する
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // DB CHECK 制約が競合に対する最終的なセーフティネット
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**トレードオフ:**
- フェーズ 1 の読み取りはトランザクションの一部ではないため、同時リクエストがフェーズ 1 とフェーズ 2 の間に在庫を枯渇させる可能性があります。データベースの `CHECK (stock >= 0)` 制約（または負の在庫でスローする `decrement()`）がこの競合をキャッチします。
- ほとんどのアプリケーションではこれは許容されます。厳密な正確性のためには、`SELECT ... FOR UPDATE` またはシリアライザブル分離レベルを使用してください（SQLite では利用不可）。

---

## ロールバックの正確性のテスト

失敗した注文の後、在庫が変化していないことを常に確認してください:

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// Widget の減少は成功するが、Gadget は失敗する → 両方ともロールバックされる必要がある
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // 在庫 1 しかない
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget は 10 にロールバックされなければならない');
assertSame(0, $this->orders->count(), '注文は作成されていない');
```

リポジトリをモックするユニットテストはこのクラスのバグをキャッチできません — 同じ SQLite/MySQL 接続を共有する統合テストのみがロールバックの正確性を検証できます。

---

## Laravel vs NENE2

Laravel の `DB::transaction()` は 1 つの接続をリクエスト全体で透過的に共有するため、注入されたモデルで動作します。NENE2 の `PdoConnectionFactory` は各呼び出しで新しい接続を返します — テスタビリティと明示的な接続制御のための意図的な設計上の選択です。その結果、コールバックスコープの executor パターンは NENE2 では**必須**です。
