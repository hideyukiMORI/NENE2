# Usar Busca Full-Text FTS5 do SQLite

A extensão FTS5 do SQLite fornece busca full-text usando um índice invertido. Este guia aborda o padrão de schema, sincronização via triggers e as armadilhas de sintaxe de query que você encontrará ao aceitar entrada do usuário.

---

## 1. Schema: tabela virtual + conteúdo externo + triggers

Use `content=<tabela>` para manter seus dados em uma tabela normal e deixar o FTS5 manter apenas o índice de busca:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    body,
    author,
    content=articles,   -- FTS5 lê conteúdo da tabela articles
    content_rowid=id    -- mapeia rowid do FTS5 para articles.id
);

-- Manter índice FTS sincronizado com a tabela base
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- Deletar linha antiga do índice, então inserir linha atualizada
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

> **Por que triggers?** O FTS5 com `content=` não rastreia mudanças automaticamente — você deve manter o índice com triggers. O padrão `INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` é a forma do FTS5 de remover uma linha do índice.

---

## 2. Query de busca

```php
$rows = $this->executor->fetchAll(
    "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
     FROM articles_fts
     JOIN articles a ON a.id = articles_fts.rowid
     WHERE articles_fts MATCH ?
     ORDER BY rank
     LIMIT ? OFFSET ?",
    [$query, $limit, $offset],
);
```

- `rank` é uma coluna virtual exposta pelo FTS5. Valores mais baixos (mais negativos) são mais relevantes. `ORDER BY rank` coloca as melhores correspondências primeiro.
- `snippet(tabela, índice_coluna, abertura, fechamento, reticências, contagem_tokens)` retorna um fragmento destacado. O índice de coluna é baseado em 0: `0` = title, `1` = body.

---

## 3. Armadilhas de sintaxe de query

### 3.1 Hífen é prefixo de filtro de coluna — não separador de frase

**Esta é a fonte mais comum de bugs ao aceitar entrada do usuário.**

O FTS5 interpreta `palavra-outra` como um filtro de coluna: busca na coluna chamada `palavra` pelo termo `outra`. Se `palavra` não é uma coluna na tabela FTS5, o SQLite lança um erro:

```
General error: 1 no such column: text
```

```
full-text   ← ERRO: "buscar coluna 'full' por 'text'" — mas 'full' não é uma coluna
full text   ← OK: query AND (corresponde a docs com "full" E "text")
"full text" ← OK: query de frase (ordem consecutiva exata)
```

Este erro se propaga como `DatabaseConnectionException` e resulta em resposta 500 a menos que você sanitize a entrada primeiro.

**Sanitize a entrada do usuário antes de passar para o FTS5:**

```php
private function sanitizeFtsQuery(string $query): string
{
    // Substituir hífens por espaços para "full-text" se tornar "full text" (lógica AND)
    return str_replace('-', ' ', $query);
}
```

Ou escapar com aspas duplas para correspondência de frase:

```php
private function sanitizeFtsQuery(string $query): string
{
    // Envolver toda a query em aspas duplas para forçar correspondência de frase
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 Sem stemming por padrão

O tokenizador padrão `unicode61` não faz stemming. `framework` não corresponde a `frameworks`, e `run` não corresponde a `running`.

Opções:

| Abordagem | Como |
|---|---|
| Correspondência exata | Use formas exatas de palavras tanto em documentos quanto em queries |
| Correspondência de prefixo | Adicione `*` ao termo da query: `framework*` corresponde a `framework`, `frameworks`, `framework-agnostic` |
| Porter stemmer | Declare `tokenize='porter ascii'` na instrução `CREATE VIRTUAL TABLE` |

**Exemplo de correspondência de prefixo:**

```php
// Usuário digita "frame" → adicionar * para corresponder "framework", "frameworks", etc.
$query = trim($userInput) . '*';
```

**Porter stemmer:**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- stemming em inglês
);
```

> O tokenizador `porter` só está disponível quando o SQLite é compilado com suporte FTS5 (builds padrão o incluem). É útil para texto em inglês; para outros idiomas, considere stemming externo antes da indexação.

### 3.3 Operadores AND / OR / NOT

Sintaxe de query FTS5:

| Sintaxe | Significado |
|---|---|
| `one two` | AND: ambos devem estar presentes |
| `one OR two` | OR: qualquer um deve estar presente |
| `one NOT two` | NOT: primeiro presente, segundo ausente |
| `"one two"` | Frase: ordem consecutiva exata |
| `one*` | Prefixo: corresponde a `one`, `ones`, etc. |
| `title:query` | Filtro de coluna: restringir correspondência à coluna `title` |

> **Nota**: `NOT` deve ser maiúsculo. `not` minúsculo é tratado como termo de busca.

---

## 4. Contar resultados de busca

Contar com uma query separada — `COUNT(*)` na correspondência FTS5:

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. Exemplo completo de repositório

```php
final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $userQuery, int $limit, int $offset): array
    {
        $query = $this->sanitizeFtsQuery($userQuery);

        return $this->executor->fetchAll(
            "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
             FROM articles_fts
             JOIN articles a ON a.id = articles_fts.rowid
             WHERE articles_fts MATCH ?
             ORDER BY rank
             LIMIT ? OFFSET ?",
            [$query, $limit, $offset],
        );
    }

    public function countSearch(string $userQuery): int
    {
        $query = $this->sanitizeFtsQuery($userQuery);
        $row   = $this->executor->fetchOne(
            'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
            [$query],
        );

        return (int) ($row['cnt'] ?? 0);
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // Substituir hífens por espaços: "full-text" → "full text" (lógica AND)
        return str_replace('-', ' ', trim($query));
    }
}
```
