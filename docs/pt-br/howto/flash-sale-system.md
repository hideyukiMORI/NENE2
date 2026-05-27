# Como Construir um Sistema de Flash Sale com NENE2

Este guia percorre a construção de um sistema de flash sale com tempo limitado e quantidade restrita, onde usuários podem comprar um produto com desconto durante uma janela de venda.

**Field Trial**: FT140  
**Versão do NENE2**: ^1.5  
**Tópicos abordados**: validação de janela de tempo, contagem de estoque com COUNT(*), constraint UNIQUE para uma compra por usuário, expressão `match` para status, teste de ataque com mentalidade de cracker

---

## O que estamos construindo

- `POST /products` — criar um produto
- `POST /sales` — criar uma flash sale (product_id, price, quantity, starts_at, ends_at)
- `GET /sales/{saleId}` — visualizar detalhes da venda com contagem restante e status
- `POST /sales/{saleId}/purchase` — comprar durante janela ativa (uma por usuário)
- `GET /sales/{saleId}/purchases` — listar todos os compradores

---

## Schema do banco de dados

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` previne que um usuário compre a mesma venda duas vezes, mesmo sob requisições concorrentes.

---

## Validação de janela de tempo

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

Armazene `starts_at` / `ends_at` como strings ISO 8601. A comparação de strings funciona corretamente para ISO 8601 porque o formato é ordenado lexicograficamente.

---

## Contagem de estoque com COUNT(*)

Em vez de manter uma coluna `remaining` mutável, contar as compras reais:

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

Então verificar:

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` é derivado no momento da leitura: `$sale['quantity'] - $purchased`. Limitar a `max(0, $remaining)` para evitar exibição negativa.

---

## Uma compra por usuário — constraint UNIQUE

`UNIQUE (sale_id, user_id)` previne duplicatas no nível do BD. `DatabaseConstraintException` mapeia para 409:

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

O handler retorna 409 quando `purchase()` retorna `false`.

---

## Status da venda com expressão match

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

Três estados: `upcoming`, `active`, `ended`. A expressão `match` é exaustiva porque `default` cobre todos os outros casos.

---

## Resultados do teste de ataque de cracker (FT140)

| ID | Ataque | Esperado | Resultado |
|----|--------|---------|-----------|
| ATK-01 | SQL injection no nome do produto | 201 (armazenado verbatim) | Pass |
| ATK-02 | Compra sem X-User-Id | 400 | Pass |
| ATK-03 | X-User-Id não numérico | não 201 | Pass |
| ATK-04 | saleId negativo na URL | não 201 | Pass |
| ATK-05 | Comprar antes do início da venda | 422 | Pass |
| ATK-06 | Comprar após encerramento da venda | 422 | Pass |
| ATK-07 | Compra dupla da mesma venda | 409 na segunda | Pass |
| ATK-08 | Esgotar estoque e tentar comprar | 422 esgotado | Pass |
| ATK-09 | Criar venda com quantity=0 | 422 | Pass |
| ATK-10 | Criar venda com preço negativo | 422 | Pass |
| ATK-11 | Comprar como usuário inexistente | 404 | Pass |
| ATK-12 | ends_at antes de starts_at | 422 | Pass |

Todos os 12 testes de ataque passam.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|---------|
| Coluna `remaining` mutável deriva sob concorrência | Contar da tabela `purchases`, derivar `remaining` no momento da leitura |
| Permitir quantity=0 via API | Validar `$quantity > 0` no handler; também `CHECK (quantity > 0)` no schema |
| Preço negativo passa pela verificação | Validar `$price >= 0`; também `CHECK (price >= 0)` no schema |
| Usuário comprando a mesma venda duas vezes | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| Comparação de tempo em strings não-ISO | Usar ISO 8601 (ex.: `date('c')`) — ordenação lexicográfica está correta |
| `ends_at` invertido com `starts_at` | Validar `$starts_at < $ends_at` antes do INSERT |
