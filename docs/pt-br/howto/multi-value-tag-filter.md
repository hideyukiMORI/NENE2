# Como Fazer: API de Filtro por Tag Multi-valor

> **Referência FT**: FT250 (`NENE2-FT/tagfilterlog`) — Filtragem de tag por parâmetro de query multi-valor

Demonstra filtragem de tags múltiplas em uma API de posts usando uma tabela join M:N normalizada.
Suporta semântica AND (posts que têm **todas** as tags fornecidas) e semântica OR
(posts que têm **qualquer** tag fornecida), com dois formatos de query do lado do cliente:
separado por vírgulas (`?tags=php,api`) e estilo array PHP (`?tags[]=php&tags[]=api`).

---

## Rotas

| Método | Caminho         | Descrição                                          |
|--------|-----------------|----------------------------------------------------|
| `POST` | `/posts`        | Criar um post com array opcional de tags           |
| `GET`  | `/posts`        | Listar posts (filtrável por tags, AND ou OR)       |
| `GET`  | `/posts/{id}`   | Obter um único post com suas tags                  |

---

## Schema: tabela join M:N

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag     TEXT    NOT NULL,
    PRIMARY KEY (post_id, tag)
);

CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag);
```

`PRIMARY KEY(post_id, tag)` é uma chave primária composta — ela tanto aplica unicidade quanto
serve como índice em `(post_id, tag)`. Um índice separado apenas em `tag` permite buscas eficientes
`WHERE tag IN (...)` independentemente de `post_id`.

**Alternativa: abordagem com coluna JSON**

As tags podem ser armazenadas como um array JSON em uma coluna TEXT na tabela `posts`:
`tags TEXT NOT NULL DEFAULT '[]'`. Isto é mais simples (sem JOIN), mas não suporta
buscas de tag indexadas e requer `json_each()` ou `json_extract()` para filtragem.
A tabela join M:N é preferida quando o desempenho de busca por tag é importante.

---

## Criação: deduplicação e ordenação alfabética de tags

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

As tags são extraídas da requisição, aparadas e filtradas para strings não-vazias.
Valores não-string (números, nulls) são convertidos para strings vazias e descartados.

Dentro de uma transação:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // Deduplicar e ordenar tags
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` deduplica e alfabetiza no PHP antes de escrever.
`INSERT OR IGNORE` é uma segunda camada de defesa — se a constraint de PK composta disparar
(por ex., uma escrita concorrente), o insert é ignorado em vez de lançar uma exceção.

A resposta retorna tags em ordem classificada para que os chamadores sempre vejam uma lista estável.

---

## Filtro AND: `HAVING COUNT(DISTINCT tag) = N`

```php
public function findByAllTags(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         GROUP BY p.id
         HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
         ORDER BY p.created_at DESC",
        [...$tags, count($tags)],
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`WHERE pt.tag IN (...)` filtra para linhas que têm pelo menos uma tag correspondente.
`GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` seleciona apenas posts que correspondem
a **todas** as N tags.

**`CAST(? AS INTEGER)` é obrigatório**: PDO vincula todos os parâmetros como strings por padrão.
No SQLite, comparar `COUNT(...)` (um inteiro) com `'2'` (uma string) funciona em tempo de execução
para casos simples, mas o cast explícito é mais seguro e documenta a intenção.

---

## Filtro OR: `SELECT DISTINCT`

```php
public function findByAnyTag(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT DISTINCT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         ORDER BY p.created_at DESC",
        $tags,
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`SELECT DISTINCT` evita linhas duplicadas quando um post corresponde a múltiplas tags da lista IN.
Nenhuma cláusula `HAVING` é necessária — qualquer tag correspondente única qualifica o post.

---

## Formato duplo de parâmetro de query

O endpoint de listagem aceita tags em dois formatos para acomodar diferentes clientes:

| Formato | Exemplo | Fonte |
|---------|---------|-------|
| Separado por vírgulas | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| Estilo array PHP | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // Estratégia 1: separado por vírgulas (nativo NENE2)
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // Estratégia 2: parâmetros de array estilo PHP (?tags[]=php&tags[]=api)
    // PSR-7 getQueryParams() analisa sintaxe de array PHP nativamente.
    // QueryStringParser do NENE2 não tem helper para isso — use acesso direto.
    $params   = $request->getQueryParams();
    $arrayVal = $params['tags'] ?? null;

    if (is_array($arrayVal)) {
        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $arrayVal),
            static fn (string $s): bool => $s !== '',
        ));

        return $filtered;
    }

    return [];
}
```

`QueryStringParser::commaSeparated()` lida com `?tags=php,api` e retorna `null` se
o parâmetro estiver ausente. Quando `null`, o fallback verifica `getQueryParams()['tags']`,
que implementações PSR-7 analisam de `?tags[]=php&tags[]=api` como um array PHP.

O parâmetro de modo seleciona AND vs OR:

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND, 'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

Valores de `mode` desconhecidos recaem para AND (o padrão mais seguro — menos resultados).

---

## Hidratação: N+1 queries por post

```php
private function hydrateWithTags(array $row): Post
{
    $tagRows = $this->executor->fetchAll(
        'SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC',
        [(int) $row['id']],
    );

    $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);

    return new Post(
        id:        (int) $row['id'],
        title:     (string) $row['title'],
        body:      (string) $row['body'],
        tags:      $tags,
        createdAt: (string) $row['created_at'],
    );
}
```

Isto realiza uma query extra por post para carregar suas tags. Para conjuntos de dados pequenos isso é
aceitável. Para conjuntos de resultados grandes, substitua por uma única query `GROUP_CONCAT` ou
`json_group_array`:

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

Depois divida `tags_csv` com `explode(',', ...)` no PHP. Note que `GROUP_CONCAT`
do SQLite não garante ordem sem `ORDER BY` dentro do agregado
(SQLite 3.39+ suporta `ORDER BY` em `GROUP_CONCAT`).

---

## Comparação AND vs OR

| Modo | Padrão SQL | Posts com `[php, api]` vs `[php]` vs `[js]` |
|------|-----------|----------------------------------------------|
| AND (`mode=all`) | `HAVING COUNT(DISTINCT tag) = N` | Apenas `[php, api]` corresponde a `?tags=php,api` |
| OR (`mode=any`) | `SELECT DISTINCT` | Tanto `[php, api]` quanto `[php]` correspondem a `?tags=php,api` |
| Sem tags | Sem filtro | Todos os posts retornados |

Lista de tags vazia (`tags=[]` ou `tags` ausente) sempre retorna todos os posts em ambos os modos.

---

## Howtos relacionados

- [`tagging-system.md`](tagging-system.md) — gerenciamento de tag/label com relacionamentos M:N com escopo de entidade
- [`tag-label-api.md`](tag-label-api.md) — taxonomia de tags com CRUD de entidade de tag e filtragem de lista
- [`note-management-with-tags.md`](note-management-with-tags.md) — tags de nota com escopo de proprietário
- [`cursor-pagination.md`](cursor-pagination.md) — combinando paginação por cursor com filtro de tag
