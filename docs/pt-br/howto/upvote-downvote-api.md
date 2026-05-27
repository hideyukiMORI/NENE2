# Como fazer: API de Upvote / Downvote

> **Referência FT**: FT347 (`NENE2-FT/votelog`) — Upvote/downvote por usuário com toggle-off (mesma direção duas vezes remove o voto), mudança de direção (up→down atomicamente), agregação de score (upvotes − downvotes), restrição UNIQUE(user_id, item_id), 15 testes PASSAM.

Este guia mostra como implementar um sistema de votação estilo Reddit/Stack Overflow: cada usuário pode dar um voto por item, removê-lo votando na mesma direção novamente, ou mudar de direção.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (item_id)  REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` garante um voto por usuário por item. `CHECK(direction IN ('up', 'down'))` rejeita qualquer outro valor no nível do banco.

## Endpoints

| Método | Caminho                       | Descrição                        |
|--------|-------------------------------|----------------------------------|
| `POST` | `/items/{id}/vote`            | Dar, alternar ou mudar voto      |
| `GET`  | `/items/{id}/score`           | Obter pontuação do item          |
| `GET`  | `/items/{id}/vote/{userId}`   | Obter voto atual do usuário      |

## Dar um Voto

```php
POST /items/1/vote
{"user_id": 42, "direction": "up"}

→ 200
{
  "vote": "up",
  "score": {
    "upvotes": 1,
    "downvotes": 0,
    "score": 1
  }
}
```

```php
POST /items/1/vote
{"user_id": 43, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 1, "downvotes": 1, "score": 0}}
```

## Toggle Off (Mesma Direção Duas Vezes)

Votar na **mesma direção** uma segunda vez remove o voto:

```php
// Primeiro voto
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// Segundo voto na mesma direção → toggle off
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` significa que o usuário não tem voto ativo neste item.

## Mudar Direção

Votar na **direção oposta** inverte o voto existente atomicamente:

```php
// Começar com upvote
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// Mudar para downvote
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## Obter Pontuação

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // upvotes − downvotes
}
```

Pontuação para um item sem votos:

```php
GET /items/1/score   // sem votos ainda
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## Obter Estado de Voto do Usuário

```php
// Sem voto ainda
GET /items/1/vote/42
→ 200  {"vote": null}

// Após upvote
GET /items/1/vote/42
→ 200  {"vote": "up"}

// Após toggle-off
GET /items/1/vote/42
→ 200  {"vote": null}
```

## Implementação

### Lógica do Handler de Voto

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // Mesma direção → toggle off
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // Direção diferente → atualizar no lugar
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // Novo voto
        $this->repo->insertVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    }

    $score = $this->repo->getScore($itemId);

    return [
        'vote'  => $activeDirection,
        'score' => $score,
    ];
}
```

### SQL de Agregação de Pontuação

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` garante valores zero quando não há votos (SUM de conjunto vazio retorna NULL).

### Padrão UPSERT de Voto

```sql
-- Inserir novo voto
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- Atualizar direção (restrição UNIQUE previne duplicata)
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- Deletar (toggle off)
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## Validação

```php
// Direção inválida
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // direction deve ser 'up' ou 'down'

// Item não existe
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## Múltiplos Usuários

```php
// Três usuários votam no mesmo item
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Sem `UNIQUE(user_id, item_id)` | Usuários podem votar várias vezes, inflando pontuações |
| `INSERT OR REPLACE` para mudança de direção | Gera novo `id` e `created_at`; perde histórico de voto; quebra trilhas de auditoria |
| Retornar 409 no toggle-off | Toggle-off é comportamento esperado, não erro; retorne o novo estado de voto (null) |
| Calcular pontuação na aplicação buscando todos os votos | O(N) por requisição; use agregação SQL com uma única query |
| Permitir `direction: null` para remover voto via body | Ambíguo; use o padrão de toggle (mesma direção duas vezes) ou um endpoint DELETE separado |
| Omitir `COALESCE` na agregação de pontuação | `SUM()` retorna `NULL` quando nenhuma linha corresponde; `null − null` falha ou retorna tipo errado |
