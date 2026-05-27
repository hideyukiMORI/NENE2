# Sistema de Votação (Upvote / Downvote)

Permita que usuários votem positivamente ou negativamente em itens. Cada usuário pode dar no máximo um voto por item. Votar na mesma direção duas vezes cancela o voto. Votar na direção oposta troca o voto.

## Visão Geral

Um sistema de votação envolve:
- **Dar voto**: upvote ou downvote em um item
- **Toggle**: dar voto na mesma direção duas vezes remove o voto
- **Trocar**: dar voto na direção oposta substitui o voto atual
- **Pontuação**: upvotes − downvotes, retornado com toda resposta de voto
- **Voto atual**: recuperar o voto atual de um usuário em um item (para destaque na UI)

## Schema do Banco de Dados

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

A restrição `UNIQUE (user_id, item_id)` aplica um voto por usuário por item no nível do banco. `CHECK (direction IN ('up', 'down'))` previne valores inválidos mesmo que a validação no nível da aplicação seja contornada.

## Direção como Enum

Use um enum backed para prevenir que valores de direção inválidos cheguem ao repositório:

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

Parse com `VoteDirection::tryFrom($dirStr)` — retorna `null` para entrada inválida, permitindo tratamento 422 limpo sem match/switch.

## Lógica de Toggle e Troca

Todos os três casos (toggle off, troca de direção, novo voto) são tratados no repositório:

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // mesma direção → toggle off
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // direção diferente → trocar
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // sem voto existente → inserir
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

O valor de retorno `?VoteDirection` informa ao handler se o voto agora está definido (`'up'`/`'down'`) ou removido (`null`).

## Retornar Pontuação com Cada Voto

Inclua a pontuação atualizada na resposta de voto para que os clientes possam atualizar contadores sem um GET separado:

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## Cálculo de Pontuação

Queries COUNT separadas por direção são mais simples e legíveis do que um único GROUP BY:

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = upvotes - downvotes`. Zero é o estado inicial antes de qualquer voto ser dado.

## Estado de Voto do Usuário

Um endpoint separado permite que a UI mostre em qual direção o usuário atual votou (para destaque de botão):

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

Retorna `null` quando o usuário não votou (ou cancelou o voto com toggle).

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Um voto por usuário por item | Restrição `UNIQUE (user_id, item_id)` no banco |
| Direção inválida rejeitada | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| Usuário/item desconhecido | Retorna 404 — sem vazamento de existência do recurso |
| Segurança de toggle | Verifica o voto atual antes de DELETE/UPDATE |

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/users` | Criar um usuário |
| `POST` | `/items` | Criar um item |
| `POST` | `/items/{itemId}/vote` | Dar, trocar ou cancelar um voto |
| `GET` | `/items/{itemId}/score` | Obter upvotes, downvotes e pontuação |
| `GET` | `/items/{itemId}/vote/{userId}` | Obter o voto atual de um usuário em um item |
