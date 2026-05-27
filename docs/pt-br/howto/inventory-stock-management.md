# Como Fazer: Gerenciamento de Estoque de Inventário

## Visão Geral

Este guia cobre a construção de uma API de gerenciamento de estoque com NENE2. As funcionalidades incluem registro de itens baseado em SKU, operações de entrada/saída de estoque, prevenção de estoque negativo e histórico de transações.

**Implementação de referência**: `../NENE2-FT/inventorylog/`

---

## Design do Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## Tabela de Rotas

| Método | Caminho | Descrição |
|--------|------|-------------|
| `POST` | `/inventory/items` | Registrar item (SKU + nome) |
| `GET` | `/inventory/items` | Listar todos os itens |
| `GET` | `/inventory/items/{id}` | Obter item por ID |
| `POST` | `/inventory/items/{id}/in` | Entrada de estoque (receber) |
| `POST` | `/inventory/items/{id}/out` | Saída de estoque (enviar) |
| `GET` | `/inventory/items/{id}/history` | Histórico de transações |

---

## Validação de SKU

Restrinja o formato do SKU para prevenir injection e garantir forma canônica:

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## Operações de Estoque

### Entrada de Estoque

Sempre segura — apenas incrementa:

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### Saída de Estoque (com guarda de estoque insuficiente)

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## Validação de Quantidade

Rejeite quantidades não-inteiras e não-positivas:

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

Isso captura tanto `"50"` (string) quanto `-1` (negativo).

---

## Códigos de Status HTTP

| Situação | Status |
|-----------|--------|
| Item criado | 201 |
| Estoque adicionado / reduzido | 200 |
| Item / histórico encontrado | 200 |
| Campo ausente ou vazio | 422 |
| Formato de SKU inválido | 422 |
| Quantidade não-inteira ou negativa | 422 |
| Item não encontrado | 404 |
| SKU duplicado | 409 |
| Estoque insuficiente | 409 |

---

## Notas

- **Atualizações atômicas**: Use `stock = stock + :qty` e `stock = stock - :qty` no SQL para manter o saldo consistente mesmo sob acesso concorrente.
- **Trilha de auditoria**: Cada mudança de estoque escreve uma linha em `stock_history` para rastreabilidade.
- **Constraint suave**: A aplicação verifica o estoque antes de decrementar. Para correção estrita sob concorrência, adicione uma constraint `CHECK (stock >= 0)` na coluna do BD ou use transações com bloqueio de linha.
