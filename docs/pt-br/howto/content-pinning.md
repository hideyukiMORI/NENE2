# Fixação de Conteúdo (Content Pinning)

Guia de implementação do recurso de fixação (pin) de conteúdo (artigos).
Explica fixação ordenada, gerenciamento de limite, adição idempotente e ajuste automático de posição.

## Visão Geral

- Usuário fixa artigos (máximo 10)
- Coluna `position` gerencia a ordem (começando em 1)
- Adição idempotente (existente retorna 200, novo retorna 201)
- A posição é compactada automaticamente após remover um pin
- A ordem pode ser alterada arbitrariamente via `PUT /pins/order`

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/pins` | Fixar artigo (idempotente) |
| `DELETE` | `/pins/{articleId}` | Remover fixação |
| `GET` | `/pins` | Listar fixações (com ordem) |
| `PUT` | `/pins/order` | Alterar ordem das fixações |

## Design do Banco de Dados

```sql
CREATE TABLE pins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,    -- começa em 1, inteiros consecutivos
    pinned_at TEXT NOT NULL,
    UNIQUE (user_id, article_id), -- cada artigo fixado uma vez por usuário
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Adição Idempotente de Pin

```php
public function pin(int $userId, int $articleId, string $now): bool
{
    $existing = $this->findPin($userId, $articleId);
    if ($existing !== null) {
        return false;  // já existe: false = 200
    }
    $nextPosition = $this->maxPosition($userId) + 1;
    $this->executor->execute('INSERT INTO pins ...', [$userId, $articleId, $nextPosition, $now]);
    return true;  // novo: true = 201
}
```

Retorno `true` → 201 Created, `false` → 200 OK (o chamador decide o status).

## Compactação de Posição Após Remoção de Pin

```php
public function unpin(int $userId, int $articleId): bool
{
    $removedPosition = (int) $existing['position'];
    $this->executor->execute('DELETE FROM pins WHERE user_id = ? AND article_id = ?', [...]);
    // Deslocar para frente os itens após a posição removida
    $this->executor->execute(
        'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
        [$userId, $removedPosition]
    );
    return true;
}
```

Ajustado automaticamente para não deixar gaps nas posições.

## Verificação de Limite

```php
if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
    $existing = $this->repository->findPin($actorId, $articleId);
    if ($existing === null) {
        return $this->responseFactory->create([
            'error' => 'pin limit reached',
            'max' => $this->repository->maxPins()
        ], 422);
    }
}
```

A verificação de limite é ignorada ao re-fixar (idempotente) um artigo já fixado.

## Reordenação (reorder)

```php
public function reorder(int $userId, array $orderedArticleIds): bool
{
    $currentPins = $this->listPins($userId);
    $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
    sort($currentIds);
    $sortedInput = $orderedArticleIds;
    sort($sortedInput);
    if ($currentIds !== $sortedInput) {
        return false;  // não corresponde à lista de pins atual
    }
    foreach ($orderedArticleIds as $position => $articleId) {
        $this->executor->execute(
            'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
            [$position + 1, $userId, $articleId]
        );
    }
    return true;
}
```

Se `article_ids` não corresponder exatamente à lista de pins atual (em qualquer ordem), retorna 422.
Aceita apenas reordenação sem adição ou remoção.

## Resposta GET /pins

```json
{
  "pins": [
    {"article_id": 3, "title": "Article 3", "position": 1, "pinned_at": "2026-05-21T10:00:00+00:00"},
    {"article_id": 1, "title": "Article 1", "position": 2, "pinned_at": "2026-05-21T09:00:00+00:00"}
  ],
  "count": 2
}
```

Ordenado por `position` ascendente. Obtido via JOIN com `ORDER BY p.position ASC`.

## Liberação do Limite (Remover Pin para Poder Adicionar Novo)

```
10 pins → DELETE /pins/{id} → 9 pins → POST /pins disponível para adicionar
```

O limite é avaliado dinamicamente pelo contagem atual, então novos pins podem ser adicionados imediatamente após remoção.
