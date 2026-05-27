# Como Fazer: API de Gerenciamento de Estoque

Este guia mostra como construir uma API de gerenciamento de estoque com ajustes de estoque e rastreamento de histórico usando NENE2.
Padrão demonstrado pelo field trial **inventorylog** (FT220, teste de ataque cracker ATK).

## Funcionalidades

- Criar itens de estoque com SKU, nome, preço e quantidade inicial (apenas admin)
- Obter detalhes do item (público)
- Ajustar estoque com delta assinado (positivo = reabastecimento, negativo = consumo)
- Detecção de estoque insuficiente → 409 Conflict
- Log completo de histórico de ajustes

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/items` | Admin | Criar item de estoque |
| `GET` | `/items/{id}` | Público | Obter item com estoque atual |
| `POST` | `/items/{id}/adjust` | Admin | Ajustar estoque (delta ± N) |
| `GET` | `/items/{id}/history` | Público | Obter histórico de ajustes |

## Padrão de Ajuste de Estoque

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // Atualização atômica + log
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## Validação de Delta

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## Resultados do Teste Cracker ATK (FT220)

- **ATK-01**: SQL injection no SKU → bloqueado pelo padrão `/\A[A-Z0-9\-]{1,32}\z/` (422)
- **ATK-01**: SQL injection no ID do caminho → bloqueado por `ctype_digit()` (404)
- **ATK-02**: Overflow de inteiro em `price_cents` → float rejeitado por `is_int()` (422)
- **ATK-03**: ID de caminho excessivamente grande → guarda `strlen > 18` (404)
- **ATK-04**: Dreno até zero limite → permitido (quantidade = 0 é válida)
- **ATK-05**: `quantity` excessivamente grande (> 1.000.000) → rejeitado (422)
- **ATK-06**: Chave admin errada/vazia → 403 (fail-closed)
- **ATK-09**: Ataque de sobre-dreno → `insufficient_stock` → 409, estoque inalterado
- **ATK-10**: `delta` float → rejeitado por `is_int()` (422)
- **ATK-11**: Requisição sem corpo → 400 (corpo JSON obrigatório)
- **ATK-12**: Respostas de erro não contêm SQLSTATE/stack traces/caminhos internos

## Padrões de Segurança

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` antes de `hash_equals()`
- **Verificações estritas `is_int()`**: price_cents, quantity, delta — rejeita floats do JSON
- **`ctype_digit()`**: validação de inteiro segura contra ReDoS para IDs de caminho
- **Padrão SKU**: `/\A[A-Z0-9\-]{1,32}\z/` bloqueia tentativas de SQL injection
- **Operações atômicas**: update + inserção no log em sequência (dentro de uma única conexão)
