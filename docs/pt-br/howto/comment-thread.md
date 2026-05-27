# Como Fazer: API de Thread de Comentários

Este guia demonstra threads de comentários com escopo por recurso, paginação, deleção somente pelo autor e override do admin.

## Visão Geral do Padrão

- Comentários pertencem a um recurso (identificado por ID inteiro).
- Qualquer usuário autenticado pode postar um comentário em qualquer recurso.
- Comentários são publicamente legíveis (sem auth necessária para listar).
- Autores podem deletar seus próprios comentários; admins podem deletar qualquer um.
- Paginação via parâmetros de query `limit` e `offset`.

## Schema

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## Padrão de Paginação

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

A resposta inclui `total` (contagem de todos os comentários do recurso), `limit` e `offset` para que os clientes possam construir controles de paginação:

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## Limitação de Valores

Valores inválidos ou fora do intervalo de limit/offset são silenciosamente limitados para padrões seguros:

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

`ctype_digit()` é usado para evitar ReDoS na query string.

## IDOR: Deleção Somente pelo Autor

Usuários não admin só podem deletar seus próprios comentários. Tentar deletar o comentário de outro usuário retorna 404 (não 403):

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## Isolamento por Recurso

Todas as consultas incluem `WHERE resource_id = :rid`, garantindo que comentários do recurso 1 nunca sejam misturados com os do recurso 2.

## Regras de Validação

| Campo | Regra |
|---|---|
| `X-User-Id` | Obrigatório para POST/DELETE; `ctype_digit`, >0 |
| `body` | Não vazio, máx 2000 chars |
| Caminho `{resourceId}` | `ctype_digit`, máx 18 chars, >0; caso contrário 404 |
| Query `limit` | Inteiro 1–100; padrão 20 |
| Query `offset` | Inteiro não negativo; padrão 0 |

## Rotas

```
POST   /resources/{resourceId}/comments  Postar um comentário (X-User-Id obrigatório)
GET    /resources/{resourceId}/comments  Listar comentários (paginado, público)
DELETE /comments/{id}                   Deletar comentário (autor ou admin)
```

## Veja Também

- Fonte FT211: `../NENE2-FT/commentlog/`
- Relacionado: `docs/howto/note-taking.md` (FT202, CRUD de notas)
- Relacionado: `docs/howto/leaderboard-ranking.md` (FT206, dados com escopo por recurso)
