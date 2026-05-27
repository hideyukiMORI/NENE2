# Como Fazer: API de Gerenciamento de Pedidos

> **Referência FT**: FT274 (`NENE2-FT/orderlog`) — Gerenciamento de pedidos: itens de linha com SKU validado, cálculo automático de total_cents, ciclo de vida de status (pending→confirmed→shipped→delivered→cancelled), IDOR → 404, override de admin, detecção de conflito de cancelamento, 36 testes PASS.
>
> Também validado em FT215 (`NENE2-FT/orderlog` precursor) — mesmo padrão, implementação anterior.

Este guia mostra como construir uma API de gerenciamento de pedidos com múltiplos itens com NENE2.

## Funcionalidades

- Criar pedidos com itens de linha (SKU + quantidade + preço unitário)
- Cálculo automático do total a partir dos itens
- Ciclo de vida de status: `pending → confirmed → shipped → delivered → cancelled`
- Proteção IDOR com escopo de usuário (retorna 404, não 403, para ocultar existência)
- Override de admin para operações entre usuários
- Cancelamento atômico com detecção de conflito (não é possível cancelar `cancelled` ou `delivered`)

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/orders` | Criar pedido com itens |
| `GET` | `/orders/{id}` | Obter pedido com itens (proprietário ou admin) |
| `POST` | `/orders/{id}/cancel` | Cancelar pedido (proprietário ou admin) |
| `GET` | `/users/{userId}/orders` | Listar pedidos de um usuário (próprio ou admin) |

## Validação de Itens

```php
/** SKU: alfanumérico maiúsculo e hífens, 1–32 chars */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// Por item:
// - sku: deve corresponder a SKU_PATTERN
// - quantity: inteiro 1–9999
// - unit_cents: inteiro não-negativo
// Máximo de 50 itens por pedido
```

## Padrão de Repositório

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // INSERT pedido, depois INSERT itens, retornar findById()
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // Retorna 'not_found' para usuário errado (proteção IDOR)
    // Retorna 'not_cancellable' para pedidos cancelled/delivered
}
```

## Proteção IDOR

Endpoints com escopo de usuário retornam `404` (não `403`) quando um usuário acessa o recurso de outro usuário:

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Cancelamento com Match Expression

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## Padrões de Segurança

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` antes de `hash_equals()`
- **`ctype_digit()`**: validação de inteiro segura contra ReDoS para IDs de caminho e header
- **`is_int()`**: verificação de tipo estrita — rejeita floats (ex.: `1.5`) passados como JSON
- **Guarda de máximo de itens**: limita a 50 itens para prevenir payloads excessivos

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar preço como FLOAT | Erros de arredondamento em ponto flutuante nos totais (use INTEGER de centavos) |
| Aceitar strings SKU de forma livre | Superfície de injeção; use allowlist com regex (ex.: `[A-Z0-9\-]{1,32}`) |
| Sem limite máximo de itens | Atacante envia array de 10.000 itens causando loop lento de INSERT |
| Calcular total no lado do cliente | O cliente pode enviar qualquer total; sempre derive de `quantity × unit_cents` |
| Retornar 403 em acesso a pedido de outro usuário | Revela que o pedido existe; use 404 para ocultar a propriedade |
| Permitir cancelamento de pedidos entregues | Pedidos cumpridos devem ser imutáveis; use máquina de estados |
| Omitir `ON DELETE CASCADE` em order_items | Excluir um pedido deixa itens órfãos |
