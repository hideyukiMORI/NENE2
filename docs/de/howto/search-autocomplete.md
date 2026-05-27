# Implementierungsleitfaden für Volltextsuche und Autovervollständigungs-API

## Übersicht

Dieser Leitfaden erklärt, wie Volltextsuche und Autovervollständigungs-Endpunkte in NENE2 implementiert werden.
Er bietet feldübergreifende Suche mit LIKE, Relevanz-Scoring und Präfix-Vervollständigung als REST API.

---

## DB-Schema

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

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/search` | Volltextsuche |
| GET | `/autocomplete` | Namenspräfix-Vervollständigung |

### Query-Parameter

**GET /search**

| Parameter | Pflicht | Standard | Beschreibung |
|---|---|---|---|
| `q` | ✓ | — | Suchanfrage (2–100 Zeichen) |
| `category` | — | — | Kategoriefilter |
| `limit` | — | 10 | Max. 50 |
| `offset` | — | 0 | Pagination |

**GET /autocomplete**

| Parameter | Pflicht | Standard | Beschreibung |
|---|---|---|---|
| `q` | ✓ | — | Präfix (2–100 Zeichen) |
| `limit` | — | 5 | Max. 10 |

---

## Implementierung

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

        // Relevanz: 0 = exakter Namenstrefffer, 1 = Name beginnt mit Anfrage, 2 = enthält irgendwo
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
        // ! als Escape-Zeichen verwenden, um Backslash-Verwirrung in SQL-String-Literalen zu vermeiden
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### RouteRegistrar (Auszug)

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

## Design-Schwerpunkte

### Sonderzeichen in LIKE maskieren

`%` und `_` sind SQL-LIKE-Platzhalter. Benutzereingaben direkt zu übergeben führt zu unbeabsichtigten Treffern aller Einträge oder ähnlichem Verhalten wie SQL-Injection.

```php
// FALSCH: Wenn Benutzer "%_" eingibt, treffen alle Einträge
$this->db->fetchAll('SELECT * FROM products WHERE name LIKE ?', ['%' . $query . '%']);

// RICHTIG: Sonderzeichen maskieren
private function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}
// SQL: WHERE name LIKE ? ESCAPE '!'
```

`!` als Escape-Zeichen verwenden, um die doppelte Maskierungs-Hölle (SQL/PHP) mit Backslash zu vermeiden.

### Relevanz-Scoring

LIKE-Suche gibt allen Ergebnissen dasselbe Gewicht, aber `CASE WHEN` weist einfache Scores zu:

| Score | Bedingung | Beispiel |
|---|---|---|
| 0 | Exakter Namenstrefffer | "Apple iPhone 15" mit "apple iphone 15" suchen |
| 1 | Name beginnt mit Anfrage | Produkte die mit "Apple" beginnen |
| 2 | In Name, Beschreibung oder Kategorie enthalten | Beschreibung enthält "ergonomic" |

```sql
CASE WHEN LOWER(name) = ? THEN 0
     WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
     ELSE 2
END AS relevance
```

Parameter werden in dieser Reihenfolge übergeben: `[$lq (für Exakttreffer), $prefix (Präfix-Muster), ...WHERE-Klausel-Parameter, $limit, $offset]`.

### Autovervollständigung ist nur Präfix-Treffer

Suche (`%query%`) und Autovervollständigung (`query%`) haben unterschiedliche Verwendungszwecke.
"Enthält"-Suche in der Autovervollständigung würde für Vorhersagen unnatürlich wirken.

```php
// Nur Präfix-Treffer: "Apple" → ["Apple iPhone 15", "Apple Watch Series 9"]
$rows = $this->db->fetchAll(
    "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
    [$escaped . '%', $limit]
);
// "Green Apple Juice" beginnt nicht mit "Apple", daher nicht enthalten
```

### Limit-Klemmung

Wenn Clients beliebige Limits senden könnten, wäre ein Abruf aller Einträge möglich. Immer serverseitig klemmen.

```php
private function clamp(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

// Suche: max 50 / Autovervollständigung: max 10
$limit = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
```

### SQLite vs. MySQL/PostgreSQL Volltextsuche

| Methode | Anwendung | Eigenschaften |
|---|---|---|
| `LIKE '%query%'` | SQLite / MySQL / PgSQL | Klein bis mittel. Kein Index (Präfix `LIKE 'q%'` hat Index) |
| SQLite FTS5 virtuelle Tabelle | SQLite | Schnelle Volltextsuche. Tokenizer-Konfiguration, integriertes Ranking |
| MySQL FULLTEXT | MySQL | `MATCH ... AGAINST` für AND/OR/Phrasensuche |
| PostgreSQL `tsvector` | PgSQL | GIN-Index, sprachliches Stemming |

LIKE reicht für Prototypen und kleinen Umfang. Bei Hunderttausenden Zeilen auf FTS umstellen.

---

## Antwort-Beispiele

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

### GET /search?q=a (q zu kurz → 422)

```json
{
  "status": 422,
  "errors": [
    { "field": "q", "message": "q muss mindestens 2 Zeichen lang sein", "code": "too_short" }
  ]
}
```

---

## Referenzimplementierung

`../NENE2-FT/searchlog/` — FT157 Feldversuch (22 Tests)
