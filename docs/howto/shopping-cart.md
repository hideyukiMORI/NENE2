# ショッピングカート API の実装ガイド

## 概要

このガイドでは NENE2 を使ってショッピングカート API を実装する方法を説明します。
カートへの商品追加・数量変更・削除・合計金額計算を REST API として提供します。

---

## DB スキーマ

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price INTEGER NOT NULL CHECK (price >= 0),
    stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at TEXT NOT NULL
);

CREATE TABLE cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    added_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

**設計ポイント**

- `UNIQUE (user_id, product_id)` で1ユーザー×1商品を1レコードに集約
- `quantity > 0` CHECK 制約でゼロ件レコードの残留を防止
- 価格はカートにスナップショットせず products テーブルから都度取得（揮発性カート）

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| `GET` | `/cart` | カート一覧と合計金額 |
| `POST` | `/cart/items` | 商品追加（既存は数量加算） |
| `PUT` | `/cart/items/{productId}` | 数量変更（0 で削除） |
| `DELETE` | `/cart/items/{productId}` | 商品削除 |
| `DELETE` | `/cart` | カート全削除 |

すべてのエンドポイントで `X-User-Id` ヘッダーが必要です（認証済みユーザー識別）。

---

## CartRepository の実装

```php
<?php
declare(strict_types=1);

namespace CartLog\Cart;

use Nene2\Database\DatabaseQueryExecutorInterface;

class CartRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name FROM users WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function getCart(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                    p.name AS product_name, p.price
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.user_id = ?
             ORDER BY ci.added_at ASC, ci.id ASC',
            [$userId]
        );
    }

    public function addItem(int $userId, int $productId, int $quantity, string $now): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );

        if ($existing !== null) {
            $newQty = (int) $existing['quantity'] + $quantity;
            $this->db->execute(
                'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
                [$newQty, $now, $existing['id']]
            );
        } else {
            $this->db->execute(
                'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, $productId, $quantity, $now, $now]
            );
        }
    }

    public function updateQuantity(int $userId, int $productId, int $quantity, string $now): void
    {
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $now, $userId, $productId]
        );
    }

    public function removeItem(int $userId, int $productId): void
    {
        $this->db->execute(
            'DELETE FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );
    }

    public function clearCart(int $userId): void
    {
        $this->db->execute('DELETE FROM cart_items WHERE user_id = ?', [$userId]);
    }
}
```

---

## RouteRegistrar の実装（抜粋）

```php
public function register(Router $router): void
{
    $router->get('/cart', $this->handleGetCart(...));
    $router->post('/cart/items', $this->handleAddItem(...));
    $router->put('/cart/items/{productId}', $this->handleUpdateItem(...));
    $router->delete('/cart/items/{productId}', $this->handleRemoveItem(...));
    $router->delete('/cart', $this->handleClearCart(...));
}
```

### 認証パターン

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

`X-User-Id` が無い・不正な場合は `401` を返します。

### 冪等な商品追加

同一商品を再度 POST した場合は 201 ではなく **200** を返し、数量を加算します。

```php
$existing = $this->repo->findCartItem($userId, $productId);
$this->repo->addItem($userId, $productId, $quantity, $now);

$status = $existing === null ? 201 : 200;
return $this->json->create($this->formatItem($item, $subtotal), $status);
```

### quantity=0 で削除

PUT `/cart/items/{productId}` に `quantity: 0` を渡すと商品を削除して 204 を返します。

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}
```

### バリデーション

```php
if (!isset($body['quantity']) || !is_int($body['quantity'])) {
    $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
}
```

`is_int()` で型を厳密に確認。文字列 `"2"` は拒否して 422 を返します。

---

## AppFactory

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
        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17 = new Psr17Factory();
        $repo = new CartRepository($executor);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
```

`RuntimeApplicationFactory` を使うことで、バリデーション例外 → 422 変換・エラーハンドリング・セキュリティヘッダーが自動で付与されます。

---

## レスポンス形式

### GET /cart

```json
{
  "items": [
    {
      "id": 1,
      "product_id": 1,
      "product_name": "Widget",
      "price": 500,
      "quantity": 3,
      "subtotal": 1500,
      "added_at": "2026-05-21T10:00:00Z",
      "updated_at": "2026-05-21T10:05:00Z"
    }
  ],
  "total": 1500,
  "count": 1
}
```

### POST /cart/items — リクエスト

```json
{ "product_id": 1, "quantity": 3 }
```

- 新規追加: 201 Created
- 既存商品（数量加算）: 200 OK

---

## テストのポイント

```php
// 同一商品を2回追加すると数量が加算される
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// ユーザーごとにカートは独立している
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

**注意**: テスト用 PDO で直接データを INSERT する際、PdoConnectionFactory が `PRAGMA foreign_keys = ON` を設定するため、JOIN で FK のないレコードが欠落します。テスト用 PDO にも同様に設定するか、JOIN 用の関連レコードも必ず INSERT してください。
