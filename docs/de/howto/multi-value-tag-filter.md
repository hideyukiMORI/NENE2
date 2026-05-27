# How-to: Multi-Wert-Tag-Filter-API

> **FT-Referenz**: FT250 (`NENE2-FT/tagfilterlog`) — Multi-Wert-Query-Parameter-Tag-Filterung

Demonstriert die Multi-Tag-Filterung einer Posts-API mit einer normalisierten M:N-Join-Tabelle. Unterstützt AND-Semantik (Posts, die **alle** angegebenen Tags haben) und OR-Semantik (Posts, die **irgendein** angegebenes Tag haben), mit zwei clientseitigen Query-Formaten: kommagetrennt (`?tags=php,api`) und PHP-Array-Stil (`?tags[]=php&tags[]=api`).

---

## Routen

| Methode | Pfad          | Beschreibung                                       |
|---------|---------------|---------------------------------------------------|
| `POST` | `/posts`      | Einen Post mit optionalem Tags-Array erstellen     |
| `GET`  | `/posts`      | Posts auflisten (nach Tags filterbar, AND oder OR) |
| `GET`  | `/posts/{id}` | Einen einzelnen Post mit seinen Tags abrufen       |

---

## Schema: M:N-Join-Tabelle

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

`PRIMARY KEY(post_id, tag)` ist ein zusammengesetzter Primärschlüssel — er erzwingt sowohl Eindeutigkeit als auch dient als Index auf `(post_id, tag)`. Ein separater Index nur auf `tag` ermöglicht effiziente `WHERE tag IN (...)`-Suchen unabhängig von `post_id`.

**Alternative: JSON-Spalten-Ansatz**

Tags können als JSON-Array in einer TEXT-Spalte der `posts`-Tabelle gespeichert werden: `tags TEXT NOT NULL DEFAULT '[]'`. Dies ist einfacher (kein JOIN), unterstützt aber keine indizierten Tag-Suchen und erfordert `json_each()` oder `json_extract()` zum Filtern. Die M:N-Join-Tabelle ist vorzuziehen, wenn die Tag-Suchperformance wichtig ist.

---

## Erstellen: Tag-Deduplizierung und alphabetische Sortierung

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

Tags werden aus dem Request extrahiert, getrimmt und auf nicht-leere Strings gefiltert. Nicht-String-Werte (Zahlen, Nulls) werden zu leeren Strings umgewandelt und verworfen.

Innerhalb einer Transaktion:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // Tags deduplizieren und sortieren
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` dedupliziert und alphabetisiert in PHP vor dem Schreiben. `INSERT OR IGNORE` ist eine zweite Verteidigungsschicht — wenn der zusammengesetzte PK-Constraint auslöst (z.B. bei gleichzeitigem Schreiben), wird der Insert übersprungen statt zu werfen.

Die Antwort gibt Tags in sortierter Reihenfolge zurück, sodass Aufrufer immer eine stabile Liste sehen.

---

## AND-Filter: `HAVING COUNT(DISTINCT tag) = N`

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

`WHERE pt.tag IN (...)` schränkt auf Zeilen ein, die mindestens ein übereinstimmendes Tag haben. `GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` wählt nur Posts aus, die **alle** N Tags hatten.

**`CAST(? AS INTEGER)` ist erforderlich**: PDO bindet alle Parameter standardmäßig als Strings. In SQLite funktioniert der Vergleich von `COUNT(...)` (ein Integer) mit `'2'` (einem String) zur Laufzeit für einfache Fälle, aber der explizite Cast ist sicherer und dokumentiert die Absicht.

---

## OR-Filter: `SELECT DISTINCT`

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

`SELECT DISTINCT` verhindert doppelte Zeilen, wenn ein Post mehrere Tags aus der IN-Liste trifft. Keine `HAVING`-Klausel nötig — ein einziges übereinstimmendes Tag qualifiziert den Post.

---

## Duales Query-Parameter-Format

Der Listen-Endpunkt akzeptiert Tags in zwei Formaten für verschiedene Clients:

| Format | Beispiel | Quelle |
|--------|---------|--------|
| Kommagetrennt | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| PHP-Array-Stil | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // Strategie 1: kommagetrennt (NENE2-nativ)
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // Strategie 2: PHP-Array-Parameter (?tags[]=php&tags[]=api)
    // PSR-7 getQueryParams() parst PHP-Array-Syntax nativ.
    // NENE2s QueryStringParser hat keinen Helper dafür — raw access verwenden.
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

`QueryStringParser::commaSeparated()` verarbeitet `?tags=php,api` und gibt `null` zurück, wenn der Parameter fehlt. Bei `null` prüft der Fallback `getQueryParams()['tags']`, was PSR-7-Implementierungen aus `?tags[]=php&tags[]=api` als PHP-Array parsen.

Der Mode-Parameter wählt AND vs OR:

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND, 'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

Unbekannte `mode`-Werte fallen auf AND zurück (der sicherere Standard — weniger Ergebnisse).

---

## Hydration: N+1-Abfrage pro Post

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

Dies führt eine zusätzliche Abfrage pro Post aus, um seine Tags zu laden. Für kleine Datensätze ist das akzeptabel. Für große Ergebnismengen durch eine einzige `GROUP_CONCAT`- oder `json_group_array`-Abfrage ersetzen:

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

Dann `tags_csv` in PHP mit `explode(',', ...)` aufteilen. Hinweis: SQLites `GROUP_CONCAT` garantiert ohne `ORDER BY` im Aggregat keine Reihenfolge (SQLite 3.39+ unterstützt `ORDER BY` in `GROUP_CONCAT`).

---

## AND vs. OR Vergleich

| Modus | SQL-Muster | Posts mit `[php, api]` vs `[php]` vs `[js]` |
|-------|------------|----------------------------------------------|
| AND (`mode=all`) | `HAVING COUNT(DISTINCT tag) = N` | Nur `[php, api]` trifft `?tags=php,api` |
| OR (`mode=any`) | `SELECT DISTINCT` | Sowohl `[php, api]` als auch `[php]` treffen `?tags=php,api` |
| Keine Tags | Kein Filter | Alle Posts werden zurückgegeben |

Eine leere Tag-Liste (`tags=[]` oder fehlende `tags`) gibt in beiden Modi immer alle Posts zurück.

---

## Verwandte Anleitungen

- [`tagging-system.md`](tagging-system.md) — Tag/Label-Verwaltung mit entitätsbezogenen M:N-Beziehungen
- [`tag-label-api.md`](tag-label-api.md) — Tag-Taxonomie mit Tag-Entity-CRUD und Listenfilterung
- [`note-management-with-tags.md`](note-management-with-tags.md) — Notiz-Tags mit Eigentümer-Scoping
- [`cursor-pagination.md`](cursor-pagination.md) — Cursor-Paginierung mit Tag-Filter kombinieren
