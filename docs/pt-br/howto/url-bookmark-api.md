# Como fazer: API de Bookmarks de URL com Filtragem por Tags

> **Referência FT**: FT265 (`NENE2-FT/linklog`) — API de Bookmarks de URL: restrição UNIQUE de URL, armazenamento de tags separadas por vírgula, correspondência de tags via LIKE

Demonstra uma API de bookmarks que armazena URLs com tags como coluna TEXT separada por vírgulas.
URLs duplicadas são detectadas via restrição `UNIQUE` e expostas como `DuplicateUrlException`
mapeada para 409 Conflict. A filtragem por tags usa quatro padrões LIKE para corresponder a uma tag independentemente
de sua posição na string separada por vírgulas.

---

## Rotas

| Método   | Caminho       | Descrição                                       |
|----------|---------------|-------------------------------------------------|
| `POST`   | `/links`      | Criar um bookmark                               |
| `GET`    | `/links`      | Listar bookmarks (busca + filtro de tag, paginado) |
| `GET`    | `/links/{id}` | Obter um único bookmark                         |
| `DELETE` | `/links/{id}` | Deletar um bookmark                             |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
```

`url TEXT NOT NULL UNIQUE` garante um bookmark por URL no nível do banco.
`tags TEXT` armazena uma lista separada por vírgulas (por exemplo, `"php,api,rest"`). Isso evita uma tabela
`link_tags` separada para casos de uso em pequena escala.

---

## Tags: TEXT separado por vírgulas vs tabela M:N

| Abordagem | Complexidade de query | Quando usar |
|---|---|---|
| TEXT separado por vírgulas | Padrões LIKE (4 por tag) | Pequenos datasets; queries de tag raras |
| Tabela M:N (`link_tags`) | JOIN + GROUP BY ou IN | Grandes datasets; filtragem AND/OR frequente |
| FTS5 com coluna de tags | `WHERE fts MATCH ?` | Busca full-text em múltiplas colunas |

TEXT separado por vírgulas é mais simples de implementar e adequado quando o número de links e tags
é modesto. Para datasets com milhares de links e queries de tag complexas (filtro AND, contagens exatas),
uma tabela de junção (veja [`multi-value-tag-filter.md`](multi-value-tag-filter.md)) é preferível.

---

## Correspondência de tags via LIKE: quatro padrões

Uma tag armazenada em uma coluna separada por vírgulas pode aparecer em quatro posições:
1. **Correspondência exata**: `tags = 'php'` (única tag)
2. **No início**: `tags LIKE 'php,%'` (primeira de múltiplas)
3. **No meio**: `tags LIKE '%,php,%'` (nem primeira nem última)
4. **No final**: `tags LIKE '%,php'` (última de múltiplas)

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // exata: "php"
        $params[]  = $tag . ',%';     // prefixo: "php,..."
        $params[]  = '%,' . $tag . ',%';  // meio: "...,php,..."
        $params[]  = '%,' . $tag;     // sufixo: "...,php"
    }
}
```

Todos os quatro padrões são combinados com AND por tag: um link deve corresponder a todas as tags solicitadas. Isso implementa
um filtro AND entre tags. Cada `?` é um binding parametrizado — sem risco de injeção.

**Limitação**: Uma query pela tag `ph` NÃO corresponderia a uma tag armazenada `php` porque os
padrões verificam delimitadores exatos (`,` ou limites de string). As tags são correspondidas pelo valor
exato da string, não como substring.

---

## Serialização e desserialização de tags separadas por vírgulas

**Armazenar**: `implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**Ler**: 
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` remove strings vazias criadas por vírgulas no início/fim ou vírgulas duplas.
`array_values()` reindexa para uma `list<string>`.

**Parsing de query de tags**: `?tags=php,api` → dividir por vírgula → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## URL duplicada: exceção customizada + handler

```php
public function create(string $url, ...): Link
{
    try {
        $this->executor->execute(
            'INSERT INTO links (url, title, description, tags, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $title, $description, implode(',', $tags), $createdAt],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new DuplicateUrlException($url);
        }
        throw $e;
    }

    return new Link($this->executor->lastInsertId(), ...);
}
```

O repositório captura a `DatabaseConnectionException` genérica (lançada pelo framework quando
ocorre uma exceção PDO), inspeciona a mensagem da exceção anterior por `UNIQUE constraint failed`,
e relança como `DuplicateUrlException` específica do domínio. Isso mantém a linguagem de domínio
(`DuplicateUrlException`) separada do detalhe de infraestrutura (`PDOException`).

O middleware `DuplicateUrlExceptionHandler` captura `DuplicateUrlException` e retorna
Problem Details 409 Conflict:

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## Busca: LIKE em título e URL

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

A query de busca é aplicada tanto à coluna `title` quanto à `url`. Um único binding `$search`
é repetido para ambas as colunas. Como na filtragem de tags, o `%` curinga é um literal SQL na
string de query, não da entrada do usuário — o termo de busca do usuário é vinculado como parâmetro.

---

## Exemplo: filtro AND de tags

**Requisição**: `GET /links?tags=php,api`

Corresponde a links que têm AMBOS `php` E `api` na coluna `tags`:
- `"php,api"` ✓ (php: correspondência de prefixo, api: correspondência de sufixo)
- `"rest,php,api"` ✓ (php: correspondência de meio, api: correspondência de sufixo)
- `"php"` ✗ (faltando `api`)

---

## Howtos relacionados

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — tabela M:N com filtragem de tag AND/OR (para datasets maiores)
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — busca full-text FTS5 como alternativa ao LIKE
- [`sql-injection-defence.md`](sql-injection-defence.md) — padrões LIKE parametrizados e defesa contra injeção
