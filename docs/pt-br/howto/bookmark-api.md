# Como Fazer: API de Favoritos

> **Referência FT**: FT295 (`NENE2-FT/bookmarklog`) — Gerenciamento de favoritos: UNIQUE(user_id, item_id) previne favoritos duplicados, agrupamento por coleção com filtro opcional, acesso com escopo por usuário (prevenção IDOR), 409 em duplicatas, 22 testes / 64 asserções PASS.

Este guia mostra como construir uma API de favoritos onde os usuários salvam itens em coleções nomeadas com deduplicação e controle de acesso com escopo por usuário.

## Schema

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` garante que cada usuário possa favoritar um item exatamente uma vez. O campo `collection` agrupa os favoritos em listas nomeadas (padrão: `'default'`).

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/users/{userId}/bookmarks` | Adicionar favorito |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Remover favorito |
| `GET` | `/users/{userId}/bookmarks` | Listar favoritos (opcionalmente filtrado por coleção) |
| `GET` | `/users/{userId}/bookmarks/count` | Contar favoritos |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Obter favorito específico |

## Ordem de Registro de Rotas

`/users/{userId}/bookmarks/count` deve ser registrado **antes** de `/users/{userId}/bookmarks/{itemId}` para evitar que `count` seja capturado como `{itemId}`:

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // estático antes de dinâmico
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## Adicionando um Favorito

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // string de coleção vazia → fallback para 'default'
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` exige `is_int()` — a string JSON `"5"` é rejeitada. A restrição `UNIQUE` no banco de dados captura condições de corrida; o repositório deve capturar a violação de restrição e retornar 409.

## Filtro de Coleção na Listagem

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Sem `?collection=`, todos os favoritos são retornados. Com `?collection=favorites`, apenas essa coleção é retornada. O parâmetro de query de coleção vazio é tratado como "sem filtro".

## Escopo por Usuário — Prevenção IDOR

Cada endpoint valida `userId` no banco de dados antes de retornar dados:

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

Solicitar `/users/999/bookmarks` como um usuário diferente retorna 404 (não os favoritos do outro usuário). Todas as consultas têm escopo para o `userId` do caminho.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem `UNIQUE(user_id, item_id)` | Usuário favorita o mesmo item várias vezes; duplicatas confusas |
| Retornar 200 em favorito duplicado | Cliente não consegue distinguir "adicionado" de "já existe"; use 409 |
| Aceitar `item_id` como string do corpo | Confusão de tipo JSON: `"5"` ≠ `5`; use `is_int()` |
| Registrar `/{itemId}` antes de `/count` | `GET /users/1/bookmarks/count` resolve para `itemId = "count"` (handler errado) |
| Sem verificação de existência do usuário | UserId inexistente retorna lista vazia em vez de 404 |
| Sem escopo por usuário nas consultas | Usuário A vê os favoritos do Usuário B (IDOR) |
| Sem padrão de coleção | Campo `collection` ausente causa crash ou deixa `NULL` no banco de dados |
