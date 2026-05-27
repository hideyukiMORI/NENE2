# Anleitung: SQLite FTS5-Volltextsuche

> **FT-Referenz**: FT254 (`NENE2-FT/ftslog`) — Volltextsuche mit SQLite FTS5

Demonstriert Volltextsuche (FTS) mit SQLites eingebauter FTS5-Erweiterung. Eine virtuelle
`posts_fts`-Tabelle spiegelt die `posts`-Tabelle und wird über Trigger synchron gehalten.
Die Suche verwendet `MATCH` mit relevanzgeordneten Ergebnissen über `fts.rank`.

---

## Routen

| Methode | Pfad             | Beschreibung                          |
|---------|------------------|--------------------------------------|
| `POST` | `/posts`         | Beitrag erstellen (automatisch indiziert) |
| `GET`  | `/posts`         | Alle Beiträge auflisten               |
| `GET`  | `/posts/search`  | Volltextsuche (`?q=`)                 |

> **Routenreihenfolge**: `/posts/search` muss **vor** `/posts/{id}` registriert werden, damit
> das wörtliche Segment `search` nicht als Pfadparameter erfasst wird.

---

## Schema: FTS5-virtuelle Tabelle + Trigger

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- leerzeichen-getrennte Tag-Zeichenkette
    created_at TEXT    NOT NULL
);

-- FTS5-virtuelle Tabelle: spiegelt posts für Volltextsuche
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- externe Inhaltstabelle
    content_rowid='id'    -- rowid-Spalte in der Inhaltstabelle
);

-- FTS-Index mit posts synchron halten
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

**`content='posts'`** deklariert `posts_fts` als Inhaltstabelle — sie speichert FTS-Tokens,
delegiert aber die eigentliche Textspeicherung an `posts`. Dies vermeidet die Duplizierung des gesamten Textes.

**`content_rowid='id'`** teilt FTS5 mit, welche Spalte in `posts` die rowid für den JOIN ist.

**Trigger** halten den FTS-Index synchron. Ohne sie würden Inserts und Updates in `posts`
nicht in `posts_fts` widergespiegelt. Der Lösch-Trigger verwendet die spezielle
`'delete'`-Befehlssyntax, um eine Zeile aus dem FTS-Index zu entfernen.

---

## Tags als leerzeichen-getrennte Zeichenkette

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// z. B. "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

Tags werden als leerzeichen-getrennte Zeichenkette gespeichert (z. B. `"php api backend"`) statt als
JSON-Array oder M:N-Join-Tabelle. Dies macht Tags durch FTS5 ohne JOIN durchsuchbar —
eine Suche nach `kubernetes` trifft einen Beitrag mit dem Tag `"docker kubernetes devops"`.

**Kompromiss**:

| Ansatz | FTS-durchsuchbar | Exakter Tag-Filter | Kanonische Tag-Entität |
|---|---|---|---|
| Leerzeichen-getrennte Zeichenkette | ✅ | ❌ (LIKE benötigt) | ❌ |
| M:N-Join-Tabelle | ❌ (JOIN erforderlich) | ✅ (IN-Klausel) | ✅ |
| JSON-Array-Spalte | Begrenzt (`json_each`) | Begrenzt | ❌ |

Den M:N-Join-Tabellen-Ansatz verwenden (siehe [`multi-value-tag-filter.md`](multi-value-tag-filter.md)),
wenn exaktes Tag-Filtern der primäre Anwendungsfall ist.

---

## Volltextsuche-Abfrage: `MATCH` + `rank`

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

`WHERE posts_fts MATCH ?` durchsucht alle indizierten Spalten (`title`, `body`, `tags`).
Der `?`-Platzhalter ist ein parametrisierter Wert — die Abfragezeichenkette wird nicht in SQL
interpoliert, sodass sie die Abfragestruktur nicht verändern kann.

`fts.rank` ist ein negativer Float — niedrigere (negativere) Werte zeigen höhere Relevanz an.
`ORDER BY fts.rank` sortiert die besten Treffer zuerst (aufsteigend, was am relevantesten-zuerst bedeutet).

---

## FTS5-Abfragesyntax

FTS5 unterstützt eine umfangreiche Abfragesprache, die als MATCH-Wert übergeben wird:

| Abfrage | Trifft |
|---------|---------|
| `php` | Jeder Beitrag, der „php" enthält |
| `php api` | Beiträge, die „php" ODER „api" enthalten (Standard: implizites OR) |
| `php AND api` | Beiträge, die sowohl „php" als auch „api" enthalten |
| `"quick brown"` | Beiträge, die die exakte Phrase „quick brown" enthalten |
| `php*` | Beiträge, bei denen ein Token mit „php" beginnt (Präfixsuche) |
| `title:php` | Beiträge, bei denen die Titelspalte „php" enthält |
| `php NOT python` | Beiträge mit „php" aber nicht „python" |

Phrasensuche (`"..."`) trifft exakte Token-Sequenzen.
Spaltenbeschränkte Suche (`title:php`) begrenzt das Treffen auf eine Spalte.

---

## Behandlung ungültiger Abfragen: try-catch → 400

FTS5 wirft eine `PDOException` (oder verpackt sie), wenn die Abfragesyntax ungültig ist:

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'q query parameter is required'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 wirft bei Syntaxfehlern (z. B. nicht geschlossene Anführungszeichen: '"unclosed')
        return $this->json->create(['error' => 'invalid search query'], 400);
    }

    return $this->json->create([...]);
}
```

Ungültige FTS-Abfragen (nicht geschlossene Anführungszeichen, fehlerhafte Operatoren) führen zu einer DB-Exception.
Das Abfangen und Zurückgeben von `400 Bad Request` verhindert, dass ein `500` zum Client durchdringt.

---

## Groß-/Kleinschreibungsunempfindlichkeit

FTS5 ist standardmäßig für ASCII-Zeichen groß-/kleinschreibungsunempfindlich. Eine Suche nach `php` trifft
Beiträge, die `PHP`, `Php` oder `php` enthalten. Nicht-ASCII-Groß-/Kleinschreibung erfordert einen benutzerdefinierten
Tokenizer (`unicode61` oder `ascii`). Der Standard-`porter`-Tokenizer wendet Stemming für englische Wörter an.

---

## Suchantwort

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Building APIs with PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

`rank` ist in jedem Ergebnis für clientseitige Anzeige oder Sortierzwecke enthalten.
Niedrigerer (negativerer) `rank` = höhere Relevanz.

---

## Vergleich: FTS5 vs. LIKE-Suche

| Funktion | FTS5 MATCH | LIKE `%term%` |
|---|---|---|
| Indiziert | ✅ | ❌ (vollständiger Scan) |
| Relevanz-Ranking | ✅ (`rank`) | ❌ |
| Mehrwort-Suche | ✅ (natürlich) | ❌ (mehrere LIKE erforderlich) |
| Phrasensuche | ✅ (`"..."`) | Teilweise (`%quick brown%`) |
| Groß-/Kleinschreibungsunempfindlich | ✅ (ASCII) | ✅ (mit NOCASE) |
| Präfixsuche | ✅ (`php*`) | ✅ (`php%`) |
| Spaltenbeschränkt | ✅ (`title:php`) | ❌ |
| Einrichtungsaufwand | FTS-virtuelle Tabelle + Trigger | Keiner |

FTS5 ist für große Datensätze bevorzugt, wo Suche eine primäre Funktion ist. LIKE ist
für kleine Tabellen oder einfache Präfix-Autovervollständigung ausreichend.

---

## Verwandte Anleitungen

- [`use-fts5-search.md`](use-fts5-search.md) — FTS5 zu einer vorhandenen Tabelle hinzufügen
- [`search-autocomplete.md`](search-autocomplete.md) — LIKE-basierte Präfix-Autovervollständigung (searchlog FT157)
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N-Tag-Filterung mit AND/OR-Semantik
- [`event-analytics-api.md`](event-analytics-api.md) — `json_extract()` für JSON-Eigenschaftssuche
