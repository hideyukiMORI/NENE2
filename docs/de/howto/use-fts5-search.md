# SQLite FTS5 Volltextsuche verwenden

SQLites FTS5-Erweiterung bietet Volltextsuche über einen invertierten Index. Diese Anleitung behandelt das Schema-Muster, triggerbasierte Synchronisation und die Abfragesyntax-Fallstricke bei der Verarbeitung von Benutzereingaben.

---

## 1. Schema: virtuelle Tabelle + externer Inhalt + Trigger

`content=<table>` verwenden, um Daten in einer normalen Tabelle zu halten und FTS5 nur den Suchindex pflegen zu lassen:

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
    content=articles,   -- FTS5 liest Inhalt aus der articles-Tabelle
    content_rowid=id    -- mappt FTS5 rowid auf articles.id
);

-- FTS-Index mit der Basistabelle synchron halten
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- Alte Zeile aus Index löschen, dann aktualisierte Zeile einfügen
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

> **Warum Trigger?** FTS5 mit `content=` verfolgt Änderungen nicht automatisch — der Index muss mit Triggern gepflegt werden. Das Muster `INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` ist FTS5s Weg, eine Zeile aus dem Index zu entfernen.

---

## 2. Suchabfrage

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

- `rank` ist eine von FTS5 bereitgestellte virtuelle Spalte. Niedrigere (negativere) Werte sind relevanter. `ORDER BY rank` stellt die besten Treffer zuerst.
- `snippet(table, column_index, open, close, ellipsis, token_count)` gibt ein hervorgehobenes Fragment zurück. Der Spaltenindex ist 0-basiert: `0` = title, `1` = body.

---

## 3. Abfragesyntax-Fallstricke

### 3.1 Bindestrich ist ein Spaltenfilter-Präfix — kein Phrasentrennzeichen

**Dies ist die häufigste Fehlerquelle bei der Verarbeitung von Benutzereingaben.**

FTS5 interpretiert `word-other` als Spaltenfilter: Es sucht in der Spalte namens `word` nach dem Begriff `other`. Wenn `word` keine Spalte in der FTS5-Tabelle ist, wirft SQLite einen Fehler:

```
General error: 1 no such column: text
```

```
full-text   ← FEHLER: "Spalte 'full' nach 'text' durchsuchen" — aber 'full' ist keine Spalte
full text   ← OK: UND-Abfrage (trifft Dokumente mit sowohl "full" ALS AUCH "text")
"full text" ← OK: Phrasenabfrage (exakte aufeinanderfolgende Reihenfolge)
```

Dieser Fehler propagiert als `DatabaseConnectionException` und führt zu einer 500-Antwort, wenn die Eingabe nicht vorher bereinigt wird.

**Benutzereingaben vor der Übergabe an FTS5 bereinigen:**

```php
private function sanitizeFtsQuery(string $query): string
{
    // Bindestriche durch Leerzeichen ersetzen: "full-text" wird zu "full text" (UND-Logik)
    return str_replace('-', ' ', $query);
}
```

Oder mit doppelten Anführungszeichen für eine Phrasenübereinstimmung escapen:

```php
private function sanitizeFtsQuery(string $query): string
{
    // Die gesamte Abfrage in doppelte Anführungszeichen einschließen, um Phrasenabgleich zu erzwingen
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 Standardmäßig kein Stemming

Der Standard-Tokenizer `unicode61` führt kein Stemming durch. `framework` trifft nicht `frameworks`, und `run` trifft nicht `running`.

Optionen:

| Ansatz | Wie |
|--------|-----|
| Exakte Übereinstimmung | Exakte Wortformen in Dokumenten und Abfragen verwenden |
| Präfix-Match | `*` an den Abfragebegriff anhängen: `framework*` trifft `framework`, `frameworks`, `framework-agnostic` |
| Porter-Stemmer | `tokenize='porter ascii'` in der `CREATE VIRTUAL TABLE`-Anweisung deklarieren |

**Präfix-Match-Beispiel:**

```php
// Benutzer tippt "frame" → * anhängen, um "framework", "frameworks" etc. zu treffen
$query = trim($userInput) . '*';
```

**Porter-Stemmer:**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- Englisches Stemming
);
```

> Der `porter`-Tokenizer ist nur verfügbar, wenn SQLite mit FTS5-Unterstützung kompiliert wurde (Standard-Builds schließen es ein). Er ist nützlich für englische Texte; für andere Sprachen externes Stemming vor der Indizierung in Betracht ziehen.

### 3.3 AND / OR / NOT-Operatoren

FTS5-Abfragesyntax:

| Syntax | Bedeutung |
|--------|-----------|
| `one two` | AND: beide müssen vorhanden sein |
| `one OR two` | OR: eines muss vorhanden sein |
| `one NOT two` | NOT: erstes vorhanden, zweites abwesend |
| `"one two"` | Phrase: exakte aufeinanderfolgende Reihenfolge |
| `one*` | Präfix: trifft `one`, `ones`, `only` (Präfix-Match auf `one`) |
| `title:query` | Spaltenfilter: Match auf die `title`-Spalte beschränken |

> **Hinweis**: `NOT` muss großgeschrieben sein. Kleingeschriebenes `not` wird als Suchbegriff behandelt.

---

## 4. Suchergebnisse zählen

Mit einer separaten Abfrage zählen — `COUNT(*)` auf dem FTS5-Match:

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. Vollständiges Repository-Beispiel

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
        // Bindestriche durch Leerzeichen ersetzen: "full-text" → "full text" (UND-Logik)
        return str_replace('-', ' ', trim($query));
    }
}
```
