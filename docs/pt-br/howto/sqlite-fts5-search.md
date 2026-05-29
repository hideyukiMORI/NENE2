# Como Fazer: Busca Full-Text SQLite FTS5

> **Referência FT**: FT254 (`NENE2-FT/ftslog`) — Busca full-text com SQLite FTS5

Demonstra busca full-text (FTS) usando a extensão FTS5 integrada do SQLite. Uma tabela virtual `posts_fts` espelha a tabela `posts` e é mantida sincronizada via triggers. A busca usa `MATCH` com resultados ranqueados por relevância via `fts.rank`.

---

## Rotas

| Método | Caminho            | Descrição                              |
|--------|--------------------|----------------------------------------|
| `POST` | `/posts`           | Criar uma postagem (indexada automaticamente) |
| `GET`  | `/posts`           | Listar todas as postagens              |
| `GET`  | `/posts/search`    | Busca full-text (`?q=`)               |

> **Ordem das rotas**: `/posts/search` deve ser registrado **antes** de `/posts/{id}` para que o segmento literal `search` não seja capturado como parâmetro de caminho.

---

## Schema: Tabela Virtual FTS5 + Triggers

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- string de tags separada por espaço
    created_at TEXT    NOT NULL
);

-- Tabela virtual FTS5: sombra posts para busca full-text
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- tabela de conteúdo externo
    content_rowid='id'    -- coluna rowid na tabela de conteúdo
);

-- Manter índice FTS em sincronia com posts
CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

**`content='posts'`** declara `posts_fts` como tabela de conteúdo — ela armazena tokens FTS mas delega o armazenamento real do texto para `posts`. Isso evita duplicar o texto completo.

**`content_rowid='id'`** informa ao FTS5 qual coluna em `posts` é o rowid a usar para o JOIN.

**Triggers** mantêm o índice FTS em sincronia. Sem eles, inserções e atualizações em `posts` não seriam refletidas em `posts_fts`. O trigger de delete usa a sintaxe de comando especial `'delete'` para remover uma linha do índice FTS.

---

## Tags como String Separada por Espaço

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// ex.: "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

Tags são armazenadas como uma string separada por espaço (ex.: `"php api backend"`) em vez de array JSON ou tabela M:N de junção. Isso torna as tags pesquisáveis pelo FTS5 sem nenhum JOIN — uma busca por `kubernetes` corresponde a uma postagem tagueada com `"docker kubernetes devops"`.

**Trade-off**:

| Abordagem | FTS pesquisável | Filtro exato de tag | Entidade canônica de tag |
|---|---|---|---|
| String separada por espaço | ✅ | ❌ (necessário LIKE) | ❌ |
| Tabela M:N de junção | ❌ (necessário JOIN) | ✅ (cláusula IN) | ✅ |
| Coluna array JSON | Limitado (`json_each`) | Limitado | ❌ |

Use a abordagem de tabela M:N de junção (veja [`multi-value-tag-filter.md`](multi-value-tag-filter.md)) quando o filtro exato de tag é o caso de uso primário.

---

## Query de Busca Full-Text: `MATCH` + `rank`

```php
public function search(string $query): array
{
    if (trim($query) === '') {
        return [];
    }

    $rows = $this->executor->fetchAll(
        'SELECT p.*, fts.rank
         FROM posts_fts fts
         JOIN posts p ON p.id = fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY fts.rank',
        [$query],
    );

    return array_map(
        static fn (array $row): SearchResult => new SearchResult(
            new Post(...),
            (float) $row['rank'],
        ),
        $rows,
    );
}
```

`WHERE posts_fts MATCH ?` busca em todas as colunas indexadas (`title`, `body`, `tags`). O placeholder `?` é um valor parametrizado — a string de query não é interpolada no SQL, então não pode alterar a estrutura da query.

`fts.rank` é um float negativo — valores menores (mais negativos) indicam maior relevância. `ORDER BY fts.rank` ordena os melhores resultados primeiro (ascendente, que é mais relevante primeiro).

---

## Sintaxe de Query FTS5

FTS5 suporta uma linguagem de query rica passada como valor MATCH:

| Query | Corresponde |
|-------|-------------|
| `php` | Qualquer postagem contendo "php" |
| `php api` | Postagens contendo **ambos** "php" E "api" (padrão: AND implícito) |
| `php AND api` | Postagens contendo ambos "php" e "api" (explícito, igual acima) |
| `php OR api` | Postagens contendo "php" ou "api" |
| `"quick brown"` | Postagens contendo a frase exata "quick brown" |
| `php*` | Postagens onde algum token começa com "php" (busca por prefixo) |
| `title:php` | Postagens onde a coluna title contém "php" |
| `php NOT python` | Postagens com "php" mas não "python" |

Busca de frase (`"..."`) corresponde a sequências exatas de tokens. Busca com escopo de coluna (`title:php`) limita a correspondência a uma coluna.

---

## Tratamento de Query Inválida: try-catch → 400

FTS5 lança `PDOException` (ou a encapsula) quando a sintaxe da query é inválida:

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'parâmetro q é obrigatório'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 lança em erros de sintaxe (ex.: aspas não fechadas: '"nao_fechada')
        return $this->json->create(['error' => 'query de busca inválida'], 400);
    }

    return $this->json->create([...]);
}
```

Queries FTS inválidas (aspas não fechadas, operadores malformados) resultam em exceção do banco. Capturá-la e retornar `400 Bad Request` previne que um `500` vaze para o cliente.

---

## Insensibilidade a Maiúsculas

FTS5 é insensível a maiúsculas por padrão para caracteres ASCII. Uma busca por `php` corresponde a postagens contendo `PHP`, `Php` ou `php`. O tratamento de maiúsculas não-ASCII requer um tokenizador personalizado (`unicode61` ou `ascii`). O tokenizador padrão `porter` aplica stemming para palavras em inglês.

---

## Resposta de Busca

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Construindo APIs com PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

`rank` é incluído em cada resultado para fins de exibição ou ordenação no lado do cliente. `rank` menor (mais negativo) = maior relevância.

---

## Comparação: FTS5 vs Busca LIKE

| Funcionalidade | FTS5 MATCH | LIKE `%termo%` |
|---|---|---|
| Indexado | ✅ | ❌ (varredura completa) |
| Ranqueamento por relevância | ✅ (`rank`) | ❌ |
| Busca multi-palavra | ✅ (natural) | ❌ (requer múltiplos LIKE) |
| Busca de frase | ✅ (`"..."`) | Parcial (`%quick brown%`) |
| Insensível a maiúsculas | ✅ (ASCII) | ✅ (com NOCASE) |
| Busca por prefixo | ✅ (`php*`) | ✅ (`php%`) |
| Escopo por coluna | ✅ (`title:php`) | ❌ |
| Custo de configuração | Tabela virtual FTS + triggers | Nenhum |

FTS5 é preferido para grandes conjuntos de dados onde busca é uma funcionalidade primária. LIKE é suficiente para tabelas pequenas ou autocomplete simples por prefixo.

---

## Howtos Relacionados

- [`use-fts5-search.md`](use-fts5-search.md) — adicionar FTS5 a uma tabela existente
- [`search-autocomplete.md`](search-autocomplete.md) — autocomplete por prefixo baseado em LIKE (searchlog FT157)
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — filtro M:N de tags com semântica AND/OR
- [`event-analytics-api.md`](event-analytics-api.md) — `json_extract()` para busca de propriedade JSON
