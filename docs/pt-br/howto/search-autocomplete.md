# Guia de Implementação de API de Busca Full-Text e Autocomplete

## Visão Geral

Este guia explica como implementar busca full-text e endpoints de autocomplete usando o NENE2.
Fornece busca cross-field com busca LIKE, pontuação de relevância e completação de prefixo como API REST.

---

## Schema do Banco de Dados

```sql
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
    created_at TEXT NOT NULL
);
```

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| GET | `/search` | Busca full-text |
| GET | `/autocomplete` | Completação de prefixo de nome |

### Parâmetros de Query

**GET /search**

| Parâmetro | Obrigatório | Padrão | Descrição |
|---|---|---|---|
| `q` | ✓ | — | Query de busca (2 a 100 caracteres) |
| `category` | — | — | Filtro de categoria |
| `limit` | — | 10 | Máximo 50 |
| `offset` | — | 0 | Paginação |

**GET /autocomplete**

| Parâmetro | Obrigatório | Padrão | Descrição |
|---|---|---|---|
| `q` | ✓ | — | Prefixo (2 a 100 caracteres) |
| `limit` | — | 5 | Máximo 10 |

---

## Implementação

### SearchRepository

```php
class SearchRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, ?string $category, int $limit, int $offset): array
    {
        $lq = strtolower($query);
        $escaped = $this->escapeLike($lq);
        $pattern = '%' . $escaped . '%';
        $prefix  = $escaped . '%';

        $whereConditions = [
            "LOWER(name) LIKE ? ESCAPE '!'",
            "LOWER(description) LIKE ? ESCAPE '!'",
            "LOWER(category) LIKE ? ESCAPE '!'",
        ];
        $whereParams = [$pattern, $pattern, $pattern];
        $whereClause = 'WHERE (' . implode(' OR ', $whereConditions) . ')';

        if ($category !== null) {
            $whereClause .= ' AND LOWER(category) = ?';
            $whereParams[] = strtolower($category);
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM products ' . $whereClause,
            $whereParams
        ) ?? ['cnt' => 0];
        $total = (int) $row['cnt'];

        // Relevância: 0 = nome exato, 1 = nome começa com query, 2 = contém em qualquer lugar
        $selectParams = [$lq, $prefix, ...$whereParams, $limit, $offset];
        $items = $this->db->fetchAll(
            "SELECT id, name, description, category, price_cents, created_at,
                    CASE WHEN LOWER(name) = ? THEN 0
                         WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
                         ELSE 2
                    END AS relevance
             FROM products " . $whereClause . "
             ORDER BY relevance ASC, id ASC
             LIMIT ? OFFSET ?",
            $selectParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<string> */
    public function autocomplete(string $prefix, int $limit): array
    {
        $escaped = $this->escapeLike(strtolower($prefix));
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
            [$escaped . '%', $limit]
        );
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    private function escapeLike(string $value): string
    {
        // Usar ! como char de escape para evitar confusão com barra invertida em literais SQL
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### RouteRegistrar (trecho)

```php
public function register(Router $router): void
{
    $router->get('/search', $this->handleSearch(...));
    $router->get('/autocomplete', $this->handleAutocomplete(...));
}

private function handleSearch(ServerRequestInterface $request): ResponseInterface
{
    $params = $request->getQueryParams();
    $q      = isset($params['q']) ? trim((string) $params['q']) : '';
    $errors = $this->validateQuery($q);

    $limit  = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $cat    = isset($params['category']) && trim((string) $params['category']) !== ''
                ? trim((string) $params['category']) : null;

    if ($errors !== []) {
        throw new ValidationException($errors);
    }

    $result = $this->repo->search($q, $cat, $limit, $offset);

    return $this->json->create([
        'query'    => $q,
        'category' => $cat,
        'total'    => $result['total'],
        'limit'    => $limit,
        'offset'   => $offset,
        'items'    => array_map($this->formatProduct(...), $result['items']),
    ]);
}
```

---

## Pontos de Design

### Escape de caracteres especiais no LIKE

`%` e `_` são wildcards do SQL LIKE. Passar a entrada do usuário sem escape pode causar correspondências indesejadas de todos os registros ou comportamento similar à injeção SQL.

```php
// Errado: usuário digitando "%_" corresponderia a todos os registros
$this->db->fetchAll('SELECT * FROM products WHERE name LIKE ?', ['%' . $query . '%']);

// Correto: escapar os caracteres especiais
private function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}
// SQL: WHERE name LIKE ? ESCAPE '!'
```

Use `!` como char de escape para evitar o inferno de duplo escape de barra invertida (SQL/PHP).

### Pontuação de Relevância

A busca LIKE dá o mesmo peso a todos os resultados, mas `CASE WHEN` adiciona uma pontuação simples:

| Pontuação | Condição | Exemplo |
|---|---|---|
| 0 | Nome corresponde exatamente | Buscar "apple iphone 15" encontra "Apple iPhone 15" |
| 1 | Nome começa com a query | Produtos que começam com "Apple" |
| 2 | Contido em nome, descrição ou categoria | Descrição contém "ergonomic" |

```sql
CASE WHEN LOWER(name) = ? THEN 0
     WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
     ELSE 2
END AS relevance
```

Os parâmetros são passados na ordem: `[$lq (string de correspondência exata), $prefix (padrão de prefixo), ...params da cláusula WHERE, $limit, $offset]`.

### Autocomplete usa apenas correspondência de prefixo

Busca (`%query%`) e autocomplete (`query%`) têm propósitos diferentes.
Retornar correspondências de "contém" para autocomplete parece não natural como previsão de texto.

```php
// Somente prefixo: "Apple" → ["Apple iPhone 15", "Apple Watch Series 9"]
$rows = $this->db->fetchAll(
    "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
    [$escaped . '%', $limit]
);
// "Green Apple Juice" não começa com "Apple", então não está incluído
```

### Limitação de Clamp

Se os clientes puderem enviar qualquer valor de limit, a busca de todos os registros se torna possível. Sempre faça clamp no servidor.

```php
private function clamp(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

// Busca: máx 50 / Autocomplete: máx 10
$limit = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
```

### SQLite vs MySQL/PostgreSQL para Busca Full-Text

| Abordagem | Aplicação | Características |
|---|---|---|
| `LIKE '%query%'` | SQLite / MySQL / PgSQL | Pequeno a médio porte. Sem índice (correspondência de prefixo `LIKE 'q%'` usa índice) |
| Tabela virtual SQLite FTS5 | SQLite | Busca full-text rápida. Tokenizer configurável, ranking integrado |
| MySQL FULLTEXT | MySQL | `MATCH ... AGAINST` com busca AND/OR/frase |
| `tsvector` PostgreSQL | PgSQL | Índice GIN, suporte a stemming de idioma |

LIKE é suficiente para protótipos ou pequena escala. Migre para FTS com centenas de milhares de linhas.

---

## Exemplos de Resposta

### GET /search?q=apple&category=Electronics

```json
{
  "query": "apple",
  "category": "Electronics",
  "total": 2,
  "limit": 10,
  "offset": 0,
  "items": [
    {
      "id": 1,
      "name": "Apple iPhone 15",
      "description": "Flagship smartphone by Apple",
      "category": "Electronics",
      "price_cents": 129900,
      "created_at": "2026-01-01T00:00:00Z"
    },
    {
      "id": 2,
      "name": "Apple Watch Series 9",
      "description": "Smartwatch with health tracking",
      "category": "Electronics",
      "price_cents": 49900,
      "created_at": "2026-01-01T00:00:00Z"
    }
  ]
}
```

### GET /autocomplete?q=Apple

```json
{
  "query": "Apple",
  "suggestions": [
    "Apple iPhone 15",
    "Apple Watch Series 9"
  ]
}
```

### GET /search?q=a (q muito curto → 422)

```json
{
  "status": 422,
  "errors": [
    { "field": "q", "message": "q must be at least 2 characters", "code": "too_short" }
  ]
}
```

---

## Implementação de Referência

`../NENE2-FT/searchlog/` — Field trial FT157 (22 testes)
