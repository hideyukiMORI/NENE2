# Anleitung: SQL-Injection-Abwehr

> **FT-Referenz**: FT264 (`NENE2-FT/injectionlog`) — SQL-Injection-Abwehr: parametrisierte Abfragen, LIKE-Injection, ORDER BY-Allowlist
> **ATK**: FT264 — Cracker-Mindset-Angrifftest (ATK-01 bis ATK-12)

Demonstriert die drei Haupt-SQL-Injection-Vektoren in einer PHP-API — Wertinjektion, LIKE-Wildcard-
Injektion und ORDER BY-Spalteninjektion — und die korrekte Abwehr für jeden. Enthält ein vollständiges
Cracker-Mindset-Angriffs-Assessment.

---

## Routen

| Methode   | Pfad            | Beschreibung                              |
|-----------|-----------------|------------------------------------------|
| `GET`    | `/products`     | Produkte auflisten/suchen (filterbar, sortierbar) |
| `POST`   | `/products`     | Produkt erstellen                         |
| `GET`    | `/products/{id}`| Einzelnes Produkt abrufen                 |
| `DELETE` | `/products/{id}`| Produkt löschen                           |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    category    TEXT    NOT NULL,
    price       REAL    NOT NULL DEFAULT 0.0,
    description TEXT    NOT NULL DEFAULT ''
);
```

---

## Die drei SQL-Injection-Angriffsflächen

### 1. Wertinjektion: parametrisierte Abfragen

```php
// ❌ String-Interpolation — injizierbar
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ Parametrisiert — der Treiber escaped alle Werte
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

PDOs `?`-Platzhalter bindet den Wert als typisierter Parameter. Der Wert wird nie in den SQL-String interpoliert. Ein Angreifer, der `id = "1; DROP TABLE products; --"` sendet, hat seine gesamte Eingabe als wörtliche String-Bindung gespeichert — das SQL wird nicht modifiziert.

### 2. LIKE-Wildcard-Injektion: parametrisierte Wildcards

```php
// ❌ Interpoliertes LIKE — injizierbar UND Wildcard-escaped
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ Parametrisierte Wildcard — der ?-Wert wird nach || Verkettung gebunden
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` ist Standard-SQL-String-Verkettung (SQLite, PostgreSQL). Der `?`-Wert wird als Parameter gebunden — die `%`-Wildcards sind Literale im SQL-String, nicht aus Benutzereingaben.

**LIKE-Metazeichen-Escaping**: `%` und `_` in der Benutzereingabe `$q` werden in dieser
Implementierung NICHT escaped. Eine Suche nach `%` würde alles treffen. Für die Produktion LIKE-Metazeichen escapen:

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. ORDER BY-Injektion: Spalten-Allowlist

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // sicher: Allowlisted Spalte + whitelisted Richtung

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` kann keine parametrisierten Platzhalter verwenden — der Spaltenname muss interpoliert werden.
Die korrekte Abwehr ist eine explizite Allowlist: Nur Werte in `ALLOWED_SORT_FIELDS` dürfen im SQL-String erscheinen. Jeder andere Wert wirft eine Exception (400 im Controller).

`sortDir` wird auf genau `'ASC'` oder `'DESC'` abgebildet — Benutzereingaben werden nie direkt interpoliert.

---

## ATK — Cracker-Mindset-Angrifftest (FT264)

### ATK-01 — Klassische SELECT-Injektion via GET-Parameter

**Angriff**: SQL via Suchanfrage `?q=' OR '1'='1` injizieren.

```
GET /products?q=' OR '1'='1
```

**Beobachtet**: `$q` ist als `?`-Parameter in `LIKE '%' || ? || '%'` gebunden. Der gesamte String
`' OR '1'='1` wird als Literal-Textwert zum Matchen behandelt. Keine zusätzlichen Zeilen werden zurückgegeben.

**Urteil**: **BLOCKED** — parametrisiertes LIKE verhindert Wertinjektion.

---

### ATK-02 — DROP TABLE-Injektion via Suche

**Angriff**: Eine destruktive Anweisung injizieren.

```
GET /products?q='; DROP TABLE products; --
```

**Beobachtet**: Der Payload ist als LIKE-Muster gebunden. `'; DROP TABLE products; --` wird als Literal-Text durchsucht. Die Tabelle wird nicht gelöscht.

**Urteil**: **BLOCKED** — parametrisierte Abfragen können keine injizierten Anweisungen ausführen.

---

### ATK-03 — ORDER BY-Spalteninjektion: beliebige Spalte

**Angriff**: Eine unbekannte Sortierspalte injizieren.

```
GET /products?sort=password
```

**Beobachtet**: `in_array('password', self::ALLOWED_SORT_FIELDS, true)` gibt `false` zurück.
`InvalidSortFieldException` wird ausgelöst. Der Controller fängt es ab und gibt 400 zurück.

**Urteil**: **BLOCKED** — Spalten-Allowlist lehnt unbekannte Spaltennamen ab.

---

### ATK-04 — ORDER BY-Injektion: Subquery-Injektion

**Angriff**: Eine Subquery als Sortierspalte injizieren.

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**Beobachtet**: Der decodierte Wert `(SELECT name FROM users LIMIT 1)` ist nicht in `ALLOWED_SORT_FIELDS`.
`InvalidSortFieldException` ausgelöst. 400 zurückgegeben.

**Urteil**: **BLOCKED** — Allowlist lehnt jeden Wert ab, der nicht in der bekannten Spaltenliste ist, einschließlich Subqueries.

---

### ATK-05 — ORDER BY-Injektion: Richtungsmanipulation

**Angriff**: SQL via Sortierrichtungsparameter injizieren.

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**Beobachtet**: `strtolower($sortDir) === 'desc'` ist `false` für den injizierten Wert. Die Richtung
fällt auf `'ASC'` zurück. Das injizierte SQL wird nie interpoliert. 200 mit nach ASC sortierten Produkten zurückgegeben.

**Urteil**: **BLOCKED** — Richtung wird auf genau `'ASC'` oder `'DESC'` abgebildet, nie interpoliert.

---

### ATK-06 — UNION-Injektion via Suchanfrage

**Angriff**: Ein `UNION SELECT` injizieren, um Daten zu exfiltrieren.

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**Beobachtet**: Der vollständige Injektions-String ist als LIKE-Parameterwert gebunden. Das `UNION SELECT`
wird als Literal-Text in den `name`- und `description`-Spalten durchsucht. Keine Benutzerdaten werden zurückgegeben.

**Urteil**: **BLOCKED** — parametrisierte Abfrage verhindert UNION-Injektion.

---

### ATK-07 — ID-Injektion via Pfadparameter

**Angriff**: SQL via Pfadparameter injizieren.

```
GET /products/1;%20DROP%20TABLE%20products;
```

**Beobachtet**: Der Pfadparameter `{id}` wird durch `(int) $params['id']` in `int` gecastet. Das SQL
wird zu `WHERE id = 1` — das Injektionssuffix wird durch den Cast abgeschnitten. Die Tabelle wird nicht gelöscht.

**Urteil**: **BLOCKED** — `(int)`-Cast schneidet beim ersten Nicht-Ziffern-Zeichen ab.

---

### ATK-08 — Boolean-basierte Blind-Injektion via Suche

**Angriff**: Daten über Boolean-Bedingungen preisgeben.

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**Beobachtet**: Beide Strings sind als LIKE-Parameter gebunden. Beide geben Produkte zurück, deren Name oder
Beschreibung den Literal-Text `' AND '1'='1` enthält. Keine Abfrage ändert die SQL WHERE-Logik. Beide geben dasselbe (leere) Ergebnisset zurück.

**Urteil**: **BLOCKED** — parametrisiertes Binden verhindert Boolean-Injektion.

---

### ATK-09 — Second-Order-Injektion: gespeicherter Payload wird später abgerufen

**Angriff**: Ein Produkt mit einem SQL enthaltenden Namen erstellen, dann alle Produkte suchen.

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**Beobachtet**: Das `INSERT` verwendet parametrisiertes `?` — der Injektions-Payload wird als Literal-Text gespeichert. Die `SELECT *`- und `LIKE`-Abfragen verwenden ebenfalls parametrisierte Abfragen. Der Payload wird als String-Wert zurückgegeben, nie als SQL ausgeführt.

**Urteil**: **BLOCKED** — alle Lese- und Schreibpfade verwenden parametrisierte Abfragen.

---

### ATK-10 — LIKE-Metazeichen-Überflutung: `%`-Suche

**Angriff**: `?q=%` senden, um alle Produkte zu matchen, wodurch eine beabsichtigte Leersuche-Vorgabe umgangen wird.

```
GET /products?q=%25   (URL-decodiert: %)
```

**Beobachtet**: `$q = '%'` ist als LIKE-Parameter gebunden. `LIKE '%' || '%' || '%'` = `LIKE '%%%'`
was jede Zeile trifft. Alle Produkte werden zurückgegeben.

**Urteil**: **EXPOSED** — `%` und `_` in Benutzereingaben werden nicht escaped. Eine Suche nach `%` trifft
alles; eine Suche nach `_` trifft jedes einzelne Zeichen. LIKE-Metazeichen escapen oder das Verhalten als absichtlich dokumentieren.

---

### ATK-11 — Null-Byte-Injektion

**Angriff**: Ein Null-Byte in die Suchanfrage einbetten.

```
GET /products?q=widget%00extra
```

**Beobachtet**: PHPs `?`-Bindung übergibt den rohen String einschließlich des Null-Bytes an SQLites
parametrisierte Abfrage. SQLite behandelt das Null-Byte als Teil des Strings. `LIKE '%widget\0extra%'`
trifft keine normalen Produktnamen. Keine Injektion tritt auf.

**Urteil**: **BLOCKED** — parametrisierte Abfragen behandeln Null-Bytes als Literal-String-Inhalt.

---

### ATK-12 — Gestapelte Abfragen (Multi-Statement-Injektion)

**Angriff**: Eine zweite Anweisung nach einem Semikolon injizieren.

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**Beobachtet**: PDO führt nur eine Anweisung pro `query()`/`prepare()`-Aufruf aus — gestapelte Abfragen
werden standardmäßig nicht unterstützt. Selbst wenn PDO mehrere Anweisungen erlauben würde, ist der Wert als
Parameter gebunden (nicht interpoliert). Das injizierte INSERT wird als Literal LIKE-Suchtext gespeichert.

**Urteil**: **BLOCKED** — parametrisiertes Binden + PDO-Einzelanweisungsmodus verhindern gestapelte Abfragen.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---|---|
| ATK-01 | Klassische SELECT-Injektion via `?q=` | BLOCKED |
| ATK-02 | DROP TABLE via Suche | BLOCKED |
| ATK-03 | ORDER BY unbekannte Spalte | BLOCKED |
| ATK-04 | ORDER BY Subquery-Injektion | BLOCKED |
| ATK-05 | Sortierrichtungs-Injektion | BLOCKED |
| ATK-06 | UNION SELECT via Suche | BLOCKED |
| ATK-07 | ID-Injektion via Pfadparameter | BLOCKED |
| ATK-08 | Boolean-basierte Blind-Injektion | BLOCKED |
| ATK-09 | Second-Order-Injektion | BLOCKED |
| ATK-10 | LIKE-Metazeichen-Überflutung (`%`) | EXPOSED |
| ATK-11 | Null-Byte-Injektion | BLOCKED |
| ATK-12 | Gestapelte Abfragen | BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **ATK-10** — LIKE-Metazeichen (`%`, `_`, `\`) vor dem Binden escapen, um Wildcard-Überflutung zu verhindern.

---

## Abwehr-Zusammenfassung

| Angriffsfläche | Verwundbares Muster | Sicheres Muster |
|---|---|---|
| Wert in WHERE | `WHERE id = {$id}` | `WHERE id = ?` mit `[$id]` |
| LIKE-Suche | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| ORDER BY-Spalte | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + interpolieren |
| ORDER BY-Richtung | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| Pfadparameter-ID | `WHERE id = {$id}` | `(int) $id` + parametrisiert |

---

## Verwandte Anleitungen

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explizites DTO-Whitelisting als breiteres Abwehrmuster
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 als Alternative zu LIKE für Volltextsuche
- [`jwt-authentication.md`](jwt-authentication.md) — VULN-Assessment einschließlich SQL-Injection (V-08)
