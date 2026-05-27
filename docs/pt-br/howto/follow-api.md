# Como Fazer: API de Seguir / Deixar de Seguir

> **Referência FT**: FT314 (`NENE2-FT/followlog`) — Grafo social de seguimento: seguir idempotente (POST 201 primeira vez, 200 na repetição), prevenção de auto-seguimento (422), deixar de seguir (DELETE 204), contadores de seguidores/seguindo via stats, listas paginadas ordenadas por mais-recente-primeiro, verificação de is-following, suporte a seguimento mútuo, 20 testes / 72 asserções PASS.

Este guia mostra como construir um sistema social de seguimento onde usuários podem seguir e deixar de seguir uns aos outros, com contadores e endpoints de lista de seguidores/seguindo.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

A constraint `UNIQUE (follower_id, followee_id)` aplica a idempotência dos relacionamentos de seguimento no nível do BD.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/users` | Criar usuário |
| `POST` | `/users/{id}/follow` | Seguir outro usuário |
| `DELETE` | `/users/{id}/follow/{followeeId}` | Deixar de seguir |
| `GET` | `/users/{id}/stats` | Obter contadores de seguidores/seguindo |
| `GET` | `/users/{id}/followers` | Listar seguidores (mais recente primeiro) |
| `GET` | `/users/{id}/following` | Listar seguindo (mais recente primeiro) |
| `GET` | `/users/{id}/is-following/{targetId}` | Verificar se está seguindo |

## Seguir Idempotente

```php
// Primeiro seguimento → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// Seguimento repetido com o mesmo par → 200 OK (não 201, não 409)
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// Lógica do handler
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // já seguindo
}
```

## Prevenção de Auto-Seguimento

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## Deixar de Seguir

```php
DELETE /users/1/follow/2
→ 204 No Content   // deixou de seguir com sucesso

DELETE /users/1/follow/2  // quando não está seguindo
→ 404 Not Found
```

O ciclo deixar de seguir/seguir novamente funciona corretamente: DELETE → POST retorna 201 novamente.

## Stats

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = quantos usuários seguem este usuário.  
`following_count` = quantos usuários este usuário segue.

Usuário desconhecido → 404.

## Listas de Seguidores / Seguindo

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- Ordenado por `follows.id DESC` (seguidor mais recente primeiro).
- Mesma estrutura para `GET /users/{id}/following`.
- Usuário desconhecido → 404.

## Verificação Is-Following

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 segue 2

GET /users/1/is-following/2  // após deixar de seguir
→ 200  {"following": false}
```

Retorna `false` (não 404) quando não está seguindo — a verificação em si é sempre válida.

## Seguimento Mútuo

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

Seguimentos mútuos são apenas duas linhas de seguimento separadas — nenhuma tabela ou lógica especial necessária.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 409 para seguimento duplicado | Lógica de retry do cliente quebra; operações idempotentes devem retornar 200, não erro |
| Permitir auto-seguimento | Corrompe stats (`followers_count` inflado por si mesmo); feeds parecem errados |
| Sem constraint UNIQUE em (follower_id, followee_id) | Condição de corrida em cliques simultâneos de seguir cria linhas duplicadas |
| DELETE de seguimento inexistente retorna 204 | Cliente não consegue distinguir "deixou de seguir" de "nunca seguiu"; use 404 |
| Ordenar por nome ou ID em vez de recência | Seguidores/seguindo mais recentes se perdem em lista longa; expectativa de UX é "quem me seguiu recentemente" |
| Contadores de seguimento compartilhados entre usuários | Contadores de seguidores sangram entre usuários não relacionados; sempre escopo por user_id |
