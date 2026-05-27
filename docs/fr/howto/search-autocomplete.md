# Guide d'implémentation de recherche plein texte et d'autocomplétion API

## Vue d'ensemble

Ce guide explique comment implémenter la recherche plein texte et les endpoints d'autocomplétion avec NENE2.
Fournit la recherche multi-champs avec LIKE, le scoring de pertinence et la complétion par préfixe comme API REST.

---

## Schéma DB

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

## Conception des endpoints

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/search` | Recherche plein texte |
| GET | `/autocomplete` | Complétion de préfixe de nom |

### Paramètres de requête

**GET /search**

| Paramètre | Requis | Défaut | Description |
|---|---|---|---|
| `q` | ✓ | — | Requête de recherche (2~100 caractères) |
| `category` | — | — | Filtre de catégorie |
| `limit` | — | 10 | Maximum 50 |
| `offset` | — | 0 | Pagination |

**GET /autocomplete**

| Paramètre | Requis | Défaut | Description |
|---|---|---|---|
| `q` | ✓ | — | Préfixe (2~100 caractères) |
| `limit` | — | 5 | Maximum 10 |

---

## Implémentation

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

        // Pertinence : 0 = nom exact, 1 = nom commence par la requête, 2 = contient partout
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
        // Utiliser ! comme caractère d'échappement pour éviter la confusion avec les backslashes dans les littéraux SQL
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### RouteRegistrar (extrait)

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

## Points clés de conception

### Échappement des caractères spéciaux LIKE

`%` et `_` sont des wildcards SQL LIKE. Passer l'entrée utilisateur directement peut causer des correspondances complètes non intentionnelles ou un comportement similaire à l'injection SQL.

```php
// NG : si l'utilisateur saisit "%_", tout correspond
$this->db->fetchAll('SELECT * FROM products WHERE name LIKE ?', ['%' . $query . '%']);

// OK : échapper les caractères spéciaux
private function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}
// SQL : WHERE name LIKE ? ESCAPE '!'
```

Utiliser `!` comme caractère d'échappement évite l'enfer du double échappement backslash (SQL/PHP).

### Scoring de pertinence

La recherche LIKE donne le même poids à tous les résultats, mais un score simple peut être ajouté avec `CASE WHEN` :

| Score | Condition | Exemple |
|---|---|---|
| 0 | Correspondance exacte du nom | Rechercher "apple iphone 15" pour "Apple iPhone 15" |
| 1 | Nom commence par la requête | Produits commençant par "Apple" |
| 2 | Contenu dans nom, description ou catégorie | Description contenant "ergonomic" |

```sql
CASE WHEN LOWER(name) = ? THEN 0
     WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
     ELSE 2
END AS relevance
```

Les paramètres sont passés dans l'ordre `[$lq (chaîne de correspondance exacte), $prefix (motif de correspondance de préfixe), ...paramètres de clause WHERE, $limit, $offset]`.

### L'autocomplétion utilise uniquement la correspondance de préfixe

La recherche (`%query%`) et l'autocomplétion (`query%`) ont des objectifs différents.
Retourner les résultats "contient" pour l'autocomplétion rend la prédiction non naturelle.

```php
// Correspondance de préfixe uniquement : "Apple" → ["Apple iPhone 15", "Apple Watch Series 9"]
$rows = $this->db->fetchAll(
    "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
    [$escaped . '%', $limit]
);
// "Green Apple Juice" ne commence pas par "Apple" donc n'est pas inclus
```

### Clamp de limit

Si les clients peuvent envoyer n'importe quelle limit, la récupération de tous les enregistrements est possible. Toujours limiter côté serveur.

```php
private function clamp(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

// Recherche : max 50 / Autocomplétion : max 10
$limit = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
```

### SQLite vs MySQL/PostgreSQL pour la recherche plein texte

| Méthode | Applicable | Caractéristiques |
|---|---|---|
| `LIKE '%query%'` | SQLite / MySQL / PgSQL | Petite à moyenne échelle. Pas d'index (correspondance de préfixe `LIKE 'q%'` utilise les index) |
| Table virtuelle SQLite FTS5 | SQLite | Recherche plein texte rapide. Configuration de tokenizer et classement intégrés |
| MySQL FULLTEXT | MySQL | Recherche AND/OR/phrase avec `MATCH ... AGAINST` |
| PostgreSQL `tsvector` | PgSQL | Index GIN, support du stemming linguistique |

LIKE est suffisant pour les prototypes et les petites échelles. Migrer vers FTS pour des centaines de milliers de lignes et plus.

---

## Exemples de réponse

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

### GET /search?q=a (q trop court → 422)

```json
{
  "status": 422,
  "errors": [
    { "field": "q", "message": "q must be at least 2 characters", "code": "too_short" }
  ]
}
```

---

## Implémentation de référence

`../NENE2-FT/searchlog/` — Field trial FT157 (22 tests)
