# Como Construir um Sistema de Pedidos de Convidado (Carrinho → Pedido → Itens do Pedido) com NENE2

Este guia percorre a construção de um fluxo de pedidos de e-commerce onde usuários adicionam produtos a um carrinho, verificam estoque e fazem um pedido que captura snapshots de preço em itens do pedido.

**Field Trial**: FT139  
**Versão do NENE2**: ^1.5  
**Tópicos abordados**: joins multi-tabela, validação de estoque, snapshot de preço em order_items, isolamento de carrinho, cálculo de total com `array_sum`

---

## O que estamos construindo

- `POST /products` — criar um produto (nome, preço, estoque)
- `POST /cart` — adicionar um produto ao carrinho (acumula quantidade se já presente)
- `GET /cart` — visualizar conteúdo do carrinho com total (X-User-Id identifica o usuário)
- `DELETE /cart/{productId}` — remover um item do carrinho
- `POST /orders` — fazer um pedido (valida estoque, decrementa estoque, limpa carrinho)
- `GET /orders/{orderId}` — visualizar detalhes do pedido com itens (apenas proprietário)

---

## Schema do banco de dados

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

`UNIQUE (user_id, product_id)` em `cart_items` previne linhas duplicadas — adicionar o mesmo produto novamente acumula a quantidade.

---

## Snapshot de preço em order_items

Quando um pedido é feito, o `name` e `price` atuais do produto são copiados para `order_items`. Isso protege pedidos históricos de futuras mudanças de preço.

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

## Acúmulo de quantidade no carrinho

`UNIQUE (user_id, product_id)` significa que um segundo `POST /cart` para o mesmo produto deve fazer UPDATE, não INSERT:

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

## Validação de estoque antes da colocação do pedido

Verificar todos os itens antes de decrementar qualquer estoque. Rollback de decremento parcial é complexo — validar primeiro, depois agir:

```php
// Validar todos os itens
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// Decrementar e criar pedido
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## Cálculo do total do carrinho

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

Isso é calculado em PHP a partir do resultado da query com join, não em SQL. O mesmo cálculo é usado para o preview do carrinho e o total armazenado no pedido.

---

## Isolamento de carrinho por usuário

Os itens do carrinho são sempre filtrados por `user_id`. Cada usuário só vê e modifica o próprio carrinho. O handler `GET /cart` retorna uma lista vazia para usuários sem itens — nunca o carrinho de outro usuário.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|---------|
| Adicionar o mesmo produto duas vezes cria linhas duplicadas | `UNIQUE (user_id, product_id)` + UPDATE em conflito |
| Mudanças de preço após colocação do pedido corrompem histórico | Copiar `name` e `price` para `order_items` no momento do pedido |
| Decremento parcial de estoque em falha multi-item | Validar todos os itens primeiro, depois decrementar todos |
| Retornar preço ao vivo do produto em detalhe do pedido | Consultar `order_items.price`, não `products.price` |
| Carrinho visível entre usuários | Sempre filtrar `cart_items` por `user_id` |
