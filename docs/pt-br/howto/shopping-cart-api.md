# Como Fazer: API de Carrinho de Compras

> **Referência FT**: FT269 (`NENE2-FT/cartlog`) — Carrinho de compras: `UNIQUE (user_id, product_id)` por usuário, semântica de upsert ao adicionar item (acumulação de quantidade), remoção automática com quantity=0, preço/subtotal como inteiro, identificação via header `X-User-Id`
>
> Também validado em FT155 (`NENE2-FT/cartlog` precursor) — mesmo padrão de carrinho, SQLite, PHP 8.4.

Demonstra um carrinho de compras stateful por usuário: adicionar itens (com acumulação de quantidade ao readicionar), atualizar quantidades, remover itens e visualizar um total corrente. Todos os preços são armazenados como inteiros (centavos ou unidades base) — nunca floats.

---

## Rotas

| Método   | Caminho                     | Descrição                                         |
|----------|-----------------------------|---------------------------------------------------|
| `GET`    | `/cart`                     | Listar conteúdo do carrinho com subtotais e total |
| `POST`   | `/cart/items`               | Adicionar produto (quantidade acumula se já no carrinho) |
| `PUT`    | `/cart/items/{productId}`   | Definir quantidade (0 = remover item)             |
| `DELETE` | `/cart/items/{productId}`   | Remover um item específico                        |
| `DELETE` | `/cart`                     | Limpar o carrinho inteiro                         |

---

## Schema

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

Escolhas de design principais:
- `UNIQUE (user_id, product_id)` — uma linha por par (usuário, produto). Readicionar o mesmo produto acumula quantidade em vez de inserir uma linha duplicada.
- `price INTEGER` — armazenado na menor unidade de moeda (ex.: centavos). Nunca use `FLOAT` para dinheiro.
- `quantity INTEGER CHECK (quantity > 0)` — linhas com quantidade zero são excluídas, não armazenadas.
- Sem FK em `cart_items.price` — o preço é lido de `products.price` no momento da query (JOIN), não armazenado no carrinho. Se o preço do produto mudar, o carrinho reflete o novo preço.

---

## Padrão de upsert ao adicionar item

Adicionar um item que já existe no carrinho acumula quantidade:

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

O padrão SELECT-then-INSERT/UPDATE evita `INSERT OR REPLACE` (que altera o `id` e `added_at`) e evita `ON CONFLICT DO UPDATE` (não portável em todos os motores de banco). A restrição `UNIQUE (user_id, product_id)` ainda protege contra um INSERT duplicado em condição de corrida.

Status de resposta: `201 Created` se o item era novo; `200 OK` se a quantidade foi acumulada em um item existente.

---

## Semântica de remoção automática com quantity=0

`PUT /cart/items/{productId}` com `quantity: 0` remove o item em vez de armazenar uma linha com quantidade zero:

```php
if ($quantity === 0) {
    // Excluir item em vez de atualizar
    $this->repo->removeItem($userId, $productId);
    return $this->json->create(['removed' => true]);
}
```

---

## Total calculado via JOIN

A resposta do carrinho inclui um total calculado em tempo real a partir do resultado do JOIN:

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

Tanto `price` quanto `subtotal` são inteiros. O consumidor da API divide por 100 para exibição (ex.: `1999` → `R$19,99`).

---

## Identificação de usuário via header X-User-Id

O FT usa um header simples `X-User-Id` (sem JWT) para identificar o proprietário do carrinho:

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

**Nota para produção**: Substitua `X-User-Id` por um JWT verificado ou token de sessão. O header é trivialmente falsificável — qualquer chamador pode reivindicar qualquer `user_id`. Use `X-User-Id` apenas em contextos confiáveis de serviço a serviço interno, nunca para APIs públicas.

---

## Validação

```php
// Validação do corpo de POST /cart/items
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

Verificações de tipo (`is_int`) rejeitam quantidades float ou string — `"3"` e `3.0` são ambos inválidos.

---

## Exemplo de Resposta

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

## Padrões de Teste

```php
// Readicionar o mesmo produto acumula quantidade
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// O carrinho de cada usuário é isolado
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Armazenar `price` em `cart_items` no momento da adição | Preço obsoleto se o preço do produto mudar; disputas de reembolso/cobrança excessiva |
| Usar `FLOAT` para preço | Erros de arredondamento de ponto flutuante em totais financeiros |
| Usar `X-User-Id` em uma API pública | Trivialmente falsificável; use JWT/sessão em vez disso |
| Permitir `quantity: 0` para armazenar linha zero | Viola `CHECK (quantity > 0)`; semântica confusa |
| Usar `INSERT OR REPLACE` para upsert | Redefine `id` e `added_at`; quebra ordenação por order preserving |
| Sem restrição `UNIQUE (user_id, product_id)` | Condição de corrida cria linhas duplicadas no carrinho |
