# 操作指南：购物车 API

> **FT 参考**：FT269（`NENE2-FT/cartlog`）— 购物车：按用户的 UNIQUE (user_id, product_id)、upsert 添加商品（数量累加）、数量=0 自动删除语义、整数价格/小计、X-User-Id 请求头标识
>
> 同样在 FT155（`NENE2-FT/cartlog` 前身）中验证——相同的购物车模式，SQLite，PHP 8.4。

演示有状态的按用户购物车：添加商品（重复添加时数量累加）、更新数量、删除商品以及查看实时总计。所有价格以整数存储（分或基本单位）——永远不用浮点数。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `GET` | `/cart` | 列出购物车内容（含小计和总计） |
| `POST` | `/cart/items` | 添加商品（已在购物车中则数量累加） |
| `PUT` | `/cart/items/{productId}` | 设置数量（0 = 删除商品） |
| `DELETE` | `/cart/items/{productId}` | 删除特定商品 |
| `DELETE` | `/cart` | 清空整个购物车 |

---

## 数据库结构

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

关键设计选择：
- `UNIQUE (user_id, product_id)` — 每个（用户，商品）对一行。重复添加同一商品会累加数量，而不是插入重复行。
- `price INTEGER` — 以最小货币单位存储（例如：分）。货币金额永远不用 `FLOAT`。
- `quantity INTEGER CHECK (quantity > 0)` — 零数量的行会被删除，不会存储。
- `cart_items.price` 无外键——价格在查询时从 `products.price` 通过 JOIN 读取，不存储在购物车中。如果商品价格变更，购物车会反映新价格。

---

## Upsert 添加商品模式

添加已在购物车中的商品时会累加数量：

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

先 SELECT 再 INSERT/UPDATE 的模式避免了 `INSERT OR REPLACE`（会改变 `id` 和 `added_at`），也避免了 `ON CONFLICT DO UPDATE`（跨 DB 引擎兼容性差）。`UNIQUE (user_id, product_id)` 约束仍然保护不受竞争条件导致的重复 INSERT 影响。

响应状态：商品为新增时返回 `201 Created`；在已有商品上累加数量时返回 `200 OK`。

---

## 数量=0 自动删除语义

`PUT /cart/items/{productId}` 传入 `quantity: 0` 时删除商品，而不是存储零数量行：

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}

$this->repo->updateQuantity($userId, $productId, $quantity, $now);
```

这与常见的购物车 UX 一致：将步进器拖到零时删除商品。DB 的 `CHECK (quantity > 0)` 也在存储层强制执行此规则。

---

## 购物车总计：JOIN + 循环计算

购物车响应包含从 JOIN 结果实时计算的总计：

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

`price` 和 `subtotal` 均为整数。API 消费者除以 100 来显示（例如：`1999` → `$19.99`）。

---

## 通过 X-User-Id 请求头标识用户

本 FT 使用简单的 `X-User-Id` 请求头（无 JWT）来标识购物车所有者：

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

处理器在继续之前验证用户是否存在于 `users` 表：
```php
if ($this->repo->findUserById($userId) === null) {
    return $this->json->create(['error' => 'User not found'], 404);
}
```

**生产注意事项**：将 `X-User-Id` 替换为经过验证的 JWT 或 session 令牌。该请求头极易伪造——任何调用者都可以声明任意 `user_id`。仅在受信任的内部服务间通信场景下使用 `X-User-Id`，永远不用于公开 API。

---

## 校验

```php
// POST /cart/items 请求体校验
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

类型检查（`is_int`）拒绝浮点数或字符串数量——`"3"` 和 `3.0` 均无效。

---

## 示例响应

**GET /cart**：
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

## AppFactory 连接示例

为测试或轻量入口点启动应用：

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

使用 `RuntimeApplicationFactory` 自动提供：校验异常 → 422 映射、错误处理和安全头。

---

## 测试模式

```php
// 重复添加同一商品时数量累加
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// 每个用户的购物车是隔离的
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

> **SQLite 外键注意事项**：`PdoConnectionFactory` 设置了 `PRAGMA foreign_keys = ON`。通过独立 PDO 实例填充测试数据时，同样要在那个连接上设置相同的 pragma——否则 JOIN 会静默丢弃其外键目标是通过不同连接句柄插入的行。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 添加时在 `cart_items` 中存储 `price` | 商品价格变化时产生陈旧价格；引发退款/多收纠纷 |
| 使用 `FLOAT` 存储价格 | 财务总计中的浮点舍入误差 |
| 在公开 API 中使用 `X-User-Id` | 极易伪造；改用 JWT/session |
| 允许 `quantity: 0` 存储零行 | 违反 `CHECK (quantity > 0)`；语义混乱 |
| 使用 `INSERT OR REPLACE` 进行 upsert | 重置 `id` 和 `added_at`；破坏保持顺序的排序 |
| 无 `UNIQUE (user_id, product_id)` 约束 | 竞争条件导致重复购物车行 |
