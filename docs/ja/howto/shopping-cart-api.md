# ハウツー: ショッピングカート API

> **FT リファレンス**: FT269 (`NENE2-FT/cartlog`) — ショッピングカート: UNIQUE (user_id, product_id) ユーザーごとのカート、アップサート商品追加（数量累積）、quantity=0 自動削除セマンティクス、整数価格/小計、X-User-Id ヘッダー識別
>
> FT155 (`NENE2-FT/cartlog` 前身) でも検証済み — 同じカートパターン、SQLite、PHP 8.4。

ステートフルなユーザーごとのショッピングカートを実演します: 商品の追加（再追加時の数量累積）、数量の更新、商品の削除、合計金額の表示。すべての価格は整数（セントまたは基本単位）として保存されます — 浮動小数点は使用しません。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `GET`    | `/cart`                     | カートの内容を小計と合計付きで一覧表示する |
| `POST`   | `/cart/items`               | 商品を追加する（すでにカートにある場合は数量が累積される） |
| `PUT`    | `/cart/items/{productId}`   | 数量を設定する（0 = 商品を削除） |
| `DELETE` | `/cart/items/{productId}`   | 特定の商品を削除する |
| `DELETE` | `/cart`                     | カート全体をクリアする |

---

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL CHECK (price >= 0),
    stock      INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL CHECK (quantity > 0),
    added_at   TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

主要な設計上の選択:
- `UNIQUE (user_id, product_id)` — （ユーザー、商品）ペアごとに 1 行。同じ商品を再追加すると重複行を挿入する代わりに数量が累積されます。
- `price INTEGER` — 最小通貨単位（例: セント）で保存。金額には `FLOAT` を使わないでください。
- `quantity INTEGER CHECK (quantity > 0)` — ゼロ数量の行は保存されず削除されます。
- `cart_items.price` に FK なし — 価格はクエリ時に `products.price` から読み取られます（JOIN）、カートには保存されません。商品価格が変わると、カートは新しい価格を反映します。

---

## アップサート商品追加パターン

すでにカートにある商品を追加すると数量が累積されます:

```php
public function addItem(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->db->fetchOne(
        'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
        [$userId, $productId],
    );

    if ($existing !== null) {
        $newQty = (int) $existing['quantity'] + $quantity;
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
            [$newQty, $now, $existing['id']],
        );
    } else {
        $this->db->execute(
            'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $productId, $quantity, $now, $now],
        );
    }
}
```

SELECT → INSERT/UPDATE パターンにより `INSERT OR REPLACE`（`id` と `added_at` が変わる）や `ON CONFLICT DO UPDATE`（すべての DB エンジンに移植可能ではない）を避けられます。`UNIQUE (user_id, product_id)` 制約は競合状態による重複 INSERT に対してもガードします。

レスポンスステータス: 新しい商品の場合は `201 Created`、既存の商品の数量が累積された場合は `200 OK`。

---

## quantity=0 自動削除セマンティクス

`quantity: 0` を指定した `PUT /cart/items/{productId}` は、ゼロ数量の行を保存する代わりに商品を削除します:

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}

$this->repo->updateQuantity($userId, $productId, $quantity, $now);
```

これは一般的なショッピングカート UX と一致します: ステッパーをゼロに引っ張ると商品が削除されます。DB の `CHECK (quantity > 0)` もストレージレベルでこれを強制します。

---

## カート合計: JOIN + ループ計算

カートレスポンスには JOIN 結果から計算されたリアルタイムの合計が含まれます:

```php
public function getCart(int $userId): array
{
    return $this->db->fetchAll(
        'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                p.name AS product_name, p.price
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at ASC, ci.id ASC',
        [$userId],
    );
}
```

```php
$items = $this->repo->getCart($userId);
$total = 0;
$formatted = [];

foreach ($items as $item) {
    $subtotal = (int) $item['price'] * (int) $item['quantity'];
    $total   += $subtotal;
    $formatted[] = $this->formatItem($item, $subtotal);
}

return $this->json->create([
    'items' => $formatted,
    'total' => $total,
    'count' => count($formatted),
]);
```

`price` と `subtotal` はどちらも整数です。API コンシューマーは表示のために 100 で割ります（例: `1999` → `$19.99`）。

---

## X-User-Id ヘッダーによるユーザー識別

FT はカートオーナーを識別するためにシンプルな `X-User-Id` ヘッダー（JWT なし）を使用します:

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $header = $request->getHeaderLine('X-User-Id');
    if ($header === '') {
        return null;
    }
    $id = (int) $header;
    return $id > 0 ? $id : null;
}
```

ハンドラーは処理を進める前にユーザーが `users` テーブルに存在することを確認します:
```php
if ($this->repo->findUserById($userId) === null) {
    return $this->json->create(['error' => 'User not found'], 404);
}
```

**本番注意**: `X-User-Id` は確認済みの JWT またはセッショントークンに置き換えてください。ヘッダーは些細な方法で偽装できます — 任意の呼び出し元が任意の `user_id` を主張できます。`X-User-Id` は信頼された内部サービス間のコンテキストのみに使用し、公開 API には使用しないでください。

---

## バリデーション

```php
// POST /cart/items ボディバリデーション
private function parseAddBody(array $body): array
{
    $errors = [];

    if (!isset($body['product_id']) || !is_int($body['product_id'])) {
        $errors[] = new ValidationError('product_id', 'product_id must be an integer', 'invalid_type');
    }

    $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : 0;
    if ($productId <= 0 && $errors === []) {
        $errors[] = new ValidationError('product_id', 'product_id must be positive', 'invalid_value');
    }

    if (!isset($body['quantity']) || !is_int($body['quantity'])) {
        $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
    }

    $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;
    if ($quantity <= 0 && !isset($errors[1])) {
        $errors[] = new ValidationError('quantity', 'quantity must be positive', 'invalid_value');
    }

    return [$productId, $quantity, $errors];
}
```

型チェック（`is_int`）により浮動小数点または文字列の数量が拒否されます — `"3"` と `3.0` はどちらも無効です。

---

## レスポンス例

**GET /cart**:
```json
{
    "items": [
        {
            "id": 1,
            "product_id": 5,
            "product_name": "Widget",
            "price": 999,
            "quantity": 2,
            "subtotal": 1998,
            "added_at": "2026-01-01T10:00:00Z",
            "updated_at": "2026-01-01T10:00:00Z"
        }
    ],
    "total": 1998,
    "count": 1
}
```

---

## AppFactory ワイヤリング例

テストまたは軽量なエントリポイント用にアプリをブートストラップします:

```php
class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        );
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $repo       = new CartRepository($executor);
        $json       = new JsonResponseFactory($psr17, $psr17);
        $registrar  = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
```

`RuntimeApplicationFactory` を使用することで、バリデーション例外 → 422 マッピング、エラーハンドリング、セキュリティヘッダーが自動的に提供されます。

---

## テストパターン

```php
// 同じ商品を再追加すると数量が累積される
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// 各ユーザーのカートは分離されている
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

> **SQLite FK の注意**: `PdoConnectionFactory` は `PRAGMA foreign_keys = ON` を設定します。別の PDO インスタンス経由でテストデータをシードする場合は、そのコネクションにも同じプラグマを設定してください — そうしないと、別のコネクションハンドル経由で挿入された FK ターゲットを持つ行が JOIN でサイレントにドロップされます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 追加時に `cart_items` に `price` を保存する | 商品価格が変わると古い価格になる; 返金/過剰請求の問題 |
| 価格に `FLOAT` を使用する | 金融合計での浮動小数点丸め誤差 |
| 公開 API で `X-User-Id` を使用する | 簡単に偽装できる; 代わりに JWT/セッションを使用する |
| `quantity: 0` のゼロ行を保存する | `CHECK (quantity > 0)` に違反; 混乱するセマンティクス |
| アップサートに `INSERT OR REPLACE` を使用する | `id` と `added_at` がリセットされる; 順序保持ソートが壊れる |
| `UNIQUE (user_id, product_id)` 制約なし | 競合状態でカートに重複行が作成される |
