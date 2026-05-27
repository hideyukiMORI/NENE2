# Coleção de Conteúdo

Guia de implementação de sistema de coleções curadas de artigos (públicas/privadas).
Explica configuração de visibilidade, prevenção de IDOR (ocultação de existência), adição idempotente e consistência de posição compactada.

## Visão Geral

- Usuário cria coleções nomeadas (listas)
- Cada coleção pode conter artigos (máximo 50)
- Flag `is_public`: coleções públicas são visíveis para qualquer pessoa
- Coleções privadas retornam 404 para não proprietários (padrão de ocultação de existência)
- Adição de artigo é idempotente (existente retorna 200, novo retorna 201)
- A posição é ajustada automaticamente após remoção de item

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/collections` | Criar coleção |
| `GET` | `/collections/{id}` | Obter coleção (pública ou própria) |
| `PUT` | `/collections/{id}` | Alterar nome/visibilidade da coleção |
| `DELETE` | `/collections/{id}` | Deletar coleção |
| `POST` | `/collections/{id}/items` | Adicionar artigo (idempotente) |
| `DELETE` | `/collections/{id}/items/{articleId}` | Remover artigo |

## Design do Banco de Dados

```sql
CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,  -- 0=private, 1=public
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    added_at TEXT NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Padrão de Ocultação de Existência (Prevenção de IDOR)

O acesso a coleções privadas retorna **404** em vez de 403.
Retornar 403 vazaria a informação de que "existe mas você não tem permissão".

```php
if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

## Adição Idempotente de Item

```php
$existing = $this->repository->findItem($id, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
}

$count = $this->repository->countItems($id);
if ($count >= CollectionRepository::maxItems()) {
    return $this->responseFactory->create(['error' => 'collection is full', 'max' => 50], 422);
}

$this->repository->addItem($id, $articleId, date('c'));
return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
```

A verificação de limite é executada apenas para artigos que ainda não foram adicionados.
Artigos já adicionados não afetam o limite (chamada idempotente).

## Consistência de Posição Após Remoção de Item

```php
public function removeItem(int $collectionId, int $articleId): void
{
    $item = $this->findItem($collectionId, $articleId);
    $removedPosition = (int) $item['position'];
    $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?', ...);
    $this->executor->execute(
        'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
        [$collectionId, $removedPosition]
    );
}
```

Itens após a posição removida são deslocados uma posição para frente para eliminar o gap.

## Exemplo de Resposta GET /collections/{id}

```json
{
  "id": 1,
  "user_id": 1,
  "name": "My Reading List",
  "is_public": false,
  "item_count": 2,
  "items": [
    {"article_id": 3, "title": "Article 3", "position": 1, "added_at": "2026-05-21T..."},
    {"article_id": 1, "title": "Article 1", "position": 2, "added_at": "2026-05-21T..."}
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Padrão de Verificação de Propriedade

Todos os endpoints de modificação (PUT/DELETE/POST items) realizam verificação de proprietário:

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Para GET, a ramificação entre 404/200 é feita com base em visibilidade pública/privada e propriedade, sem usar 403.
