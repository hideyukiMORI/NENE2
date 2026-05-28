# Anleitung: URL-Lesezeichen-API mit Tag-Filterung

> **FT-Referenz**: FT265 (`NENE2-FT/linklog`) — URL-Lesezeichen-API: UNIQUE-URL-Bedingung, komma-getrennte Tag-Speicherung, LIKE-basiertes Tag-Matching

Demonstriert eine Lesezeichen-API, die URLs mit Tags als komma-getrennte TEXT-Spalte speichert.
Doppelte URLs werden über eine `UNIQUE`-Bedingung erkannt und als `DuplicateUrlException`
auf 409 Conflict abgebildet. Tag-Filterung verwendet vier LIKE-Muster, um einen Tag unabhängig
von seiner Position in der komma-getrennten Zeichenkette zu treffen.

---

## Routen

| Methode   | Pfad          | Beschreibung                                       |
|-----------|---------------|----------------------------------------------------|
| `POST`   | `/links`      | Lesezeichen erstellen                              |
| `GET`    | `/links`      | Lesezeichen auflisten (Suche + Tag-Filter, paginiert) |
| `GET`    | `/links/{id}` | Einzelnes Lesezeichen abrufen                      |
| `DELETE` | `/links/{id}` | Lesezeichen löschen                                |

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

`url TEXT NOT NULL UNIQUE` erzwingt ein Lesezeichen pro URL auf DB-Ebene.
`tags TEXT` speichert eine komma-getrennte Liste (z. B. `"php,api,rest"`). Dies vermeidet eine separate
`link_tags`-Join-Tabelle für kleine Anwendungsfälle.

---

## Tags: Komma-getrennte TEXT vs. M:N-Join-Tabelle

| Ansatz | Abfrage-Komplexität | Wann verwenden |
|--------|---------------------|----------------|
| Komma-getrennte TEXT | LIKE-Muster (4 pro Tag) | Kleine Datensätze; seltene Tag-Abfragen |
| M:N-Join-Tabelle (`link_tags`) | JOIN + GROUP BY oder IN | Große Datensätze; häufige AND/OR-Filterung |
| FTS5 mit Tags-Spalte | `WHERE fts MATCH ?` | Volltextsuche über mehrere Spalten |

Komma-getrennte TEXT ist einfacher zu implementieren und geeignet, wenn die Anzahl der Links und Tags
gering ist. Für Datensätze mit Tausenden von Links und komplexen Tag-Abfragen (AND-Filter, exakte
Anzahlen) ist eine Join-Tabelle (siehe [`multi-value-tag-filter.md`](multi-value-tag-filter.md)) vorzuziehen.

---

## Tag-LIKE-Matching: Vier Muster

Ein in einer komma-getrennten Spalte gespeicherter Tag kann an vier Positionen erscheinen:
1. **Exakte Übereinstimmung**: `tags = 'php'` (einziger Tag)
2. **Am Anfang**: `tags LIKE 'php,%'` (erster von mehreren)
3. **In der Mitte**: `tags LIKE '%,php,%'` (nicht erster, nicht letzter)
4. **Am Ende**: `tags LIKE '%,php'` (letzter von mehreren)

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // exakt: "php"
        $params[]  = $tag . ',%';     // Präfix: "php,..."
        $params[]  = '%,' . $tag . ',%';  // Mitte: "...,php,..."
        $params[]  = '%,' . $tag;     // Suffix: "...,php"
    }
}
```

Alle vier Muster sind pro Tag mit AND verknüpft: Ein Link muss alle angeforderten Tags erfüllen. Dies implementiert
einen AND-Filter über Tags. Jedes `?` ist eine parametrisierte Bindung — kein Injektionsrisiko.

**Einschränkung**: Eine Abfrage nach Tag `ph` würde KEINEN gespeicherten Tag `php` treffen, weil
die Muster exakte Begrenzer (`,` oder Zeichenkettengrenzen) prüfen. Tags werden durch exakten
Zeichenkettenwert verglichen, nicht durch Teilzeichenkette.

---

## Komma-getrennte Tag-Serialisierung und Deserialisierung

**Speichern**: `implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**Lesen**: 
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` entfernt leere Strings, die durch führende/nachfolgende Kommas oder Doppelkommas erstellt werden.
`array_values()` reindiziert auf eine `list<string>`.

**Tag-Abfrage-Parsing**: `?tags=php,api` → nach Komma aufteilen → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## Doppelte URL: Benutzerdefinierte Exception + Handler

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

Das Repository fängt die generische `DatabaseConnectionException` (vom Framework geworfen, wenn eine
PDO-Exception auftritt), untersucht die Meldung der vorherigen Exception nach `UNIQUE constraint failed`
und wirft erneut als domänenspezifische `DuplicateUrlException`. Dies hält die Domänensprache
(`DuplicateUrlException`) von dem Infrastrukturdetail (`PDOException`) getrennt.

Das `DuplicateUrlExceptionHandler`-Middleware fängt `DuplicateUrlException` ab und gibt
409 Conflict Problem Details zurück:

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## Suche: LIKE auf Titel und URL

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

Die Suchanfrage wird sowohl auf `title`- als auch auf `url`-Spalten angewendet. Eine einzelne `$search`-Bindung
wird für beide Spalten wiederholt. Wie bei der Tag-Filterung ist der Wildcard `%` ein SQL-Literal in
der Abfragezeichenkette, nicht aus Benutzereingaben — der Suchbegriff des Benutzers wird als Parameter gebunden.

---

## Beispiel: Tag-AND-Filter

**Anfrage**: `GET /links?tags=php,api`

Trifft Links, die BEIDE `php` UND `api` in ihrer `tags`-Spalte haben:
- `"php,api"` ✓ (php: Präfix-Match, api: Suffix-Match)
- `"rest,php,api"` ✓ (php: Mitte-Match, api: Suffix-Match)
- `"php"` ✗ (fehlendes `api`)

---

## Verwandte Anleitungen

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N-Join-Tabelle mit AND/OR-Tag-Filterung (für größere Datensätze)
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5-Volltextsuche als Alternative zu LIKE
- [`sql-injection-defence.md`](sql-injection-defence.md) — Parametrisierte LIKE-Muster und Injektions-Abwehr
