# How-to: SQL-Injection-Abwehr

> **FT-Referenz**: FT264 (`NENE2-FT/injectionlog`) — SQL-Injection-Abwehr: parametrisierte Abfragen,
> LIKE-Injection, ORDER BY-Allowlist
> **ATK**: FT264 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert die drei hauptsächlichen SQL-Injection-Vektoren in einer PHP-API — Wert-Injection,
LIKE-Wildcard-Injection und ORDER BY-Spalten-Injection — sowie die korrekte Abwehr für jeden.
Enthält eine vollständige Cracker-Mindset-Angriffsbewertung.

---

## Routen

| Methode   | Pfad            | Beschreibung                               |
|-----------|-----------------|--------------------------------------------|
| `GET`     | `/products`     | Produkte auflisten/suchen (filterbar, sortierbar) |
| `POST`    | `/products`     | Produkt erstellen                          |
| `GET`     | `/products/{id}`| Einzelnes Produkt abrufen                  |
| `DELETE`  | `/products/{id}`| Produkt löschen                            |

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

### 1. Wert-Injection: parametrisierte Abfragen

```php
// ❌ String-Interpolation — injizierbar
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ Parametrisiert — der Treiber escaped alle Werte
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

Der `?`-Platzhalter von PDO bindet den Wert als typisierten Parameter. Der Wert wird nie in den
SQL-String interpoliert. Ein Angreifer, der `id = "1; DROP TABLE products; --"` sendet, bekommt
seine gesamte Eingabe als wörtliche Zeichenketten-Bindung gespeichert — das SQL wird nicht geändert.

### 2. LIKE-Wildcard-Injection: parametrisierte Wildcards

```php
// ❌ Interpoliertes LIKE — injizierbar UND wildcard-escapiert
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ Parametrisierte Wildcard — der ?-Wert wird nach der ||-Konkatenation gebunden
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` ist Standard-SQL-Zeichenketten-Konkatenation (SQLite, PostgreSQL). Der `?`-Wert
wird als Parameter gebunden — die `%`-Wildcards sind Literale im SQL-String, nicht aus Benutzereingaben.

**LIKE-Metazeichen-Escape**: `%` und `_` in der Benutzereingabe `$q` werden in dieser Implementierung
NICHT escaped. Eine Suche nach `%` würde alles treffen. Für Produktion LIKE-Metazeichen escapen:

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. ORDER BY-Injection: Spalten-Allowlist

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // sicher: allowlisted Spalte + whitelisted Richtung

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` kann keine parametrisierten Platzhalter verwenden — der Spaltenname muss interpoliert werden.
Die korrekte Abwehr ist eine explizite Allowlist: nur Werte in `ALLOWED_SORT_FIELDS` dürfen im SQL-String
erscheinen. Jeder andere Wert wirft eine Exception (400 im Controller).

`sortDir` wird genau auf `'ASC'` oder `'DESC'` abgebildet — Benutzereingaben werden nie direkt interpoliert.

---

## ATK — Cracker-Mindset-Angriffstest (FT264)

### ATK-01 — Klassische SELECT-Injection via GET-Parameter

**Angriff**: SQL via die Suchabfrage `?q=' OR '1'='1` einschleusen.

```
GET /products?q=' OR '1'='1
```

**Beobachtet**: `$q` wird als `?`-Parameter in `LIKE '%' || ? || '%'` gebunden. Die gesamte Zeichenkette
`' OR '1'='1` wird als wörtlicher Textwert behandelt, nach dem gesucht wird. Es werden keine
zusätzlichen Zeilen zurückgegeben.

**Urteil**: **BLOCKIERT** — parametrisiertes LIKE verhindert Wert-Injection.

---

### ATK-02 — DROP TABLE-Injection via Suche

**Angriff**: Eine destruktive Anweisung einschleusen.

```
GET /products?q='; DROP TABLE products; --
```

**Beobachtet**: Der Payload wird als LIKE-Parameterwert gebunden. `'; DROP TABLE products; --` wird
als wörtlicher Text gesucht. Die Tabelle wird nicht gedroppt.

**Urteil**: **BLOCKIERT** — parametrisierte Abfragen können keine eingeschleusten Anweisungen ausführen.

---

### ATK-03 — ORDER BY-Spalten-Injection: beliebige Spalte

**Angriff**: Eine nicht erkannte Sortierspalte einschleusen.

```
GET /products?sort=password
```

**Beobachtet**: `in_array('password', self::ALLOWED_SORT_FIELDS, true)` gibt `false` zurück.
`InvalidSortFieldException` wird geworfen. Der Controller fängt sie und gibt 400 zurück.

**Urteil**: **BLOCKIERT** — Spalten-Allowlist lehnt unbekannte Spaltennamen ab.

---

### ATK-04 — ORDER BY-Injection: Unterabfrage-Injection

**Angriff**: Eine Unterabfrage als Sortierspalte einschleusen.

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**Beobachtet**: Der dekodierte Wert `(SELECT name FROM users LIMIT 1)` ist nicht in `ALLOWED_SORT_FIELDS`.
`InvalidSortFieldException` wird geworfen. 400 zurückgegeben.

**Urteil**: **BLOCKIERT** — Allowlist lehnt jeden Wert ab, der nicht in der bekannten Spaltenliste steht, einschließlich Unterabfragen.

---

### ATK-05 — ORDER BY-Injection: Richtungsmanipulation

**Angriff**: SQL via den Sortierrichtungsparameter einschleusen.

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**Beobachtet**: `strtolower($sortDir) === 'desc'` ist `false` für den eingeschleusten Wert. Die Richtung
fällt durch auf `'ASC'`. Das eingeschleuste SQL wird nie interpoliert. 200 zurückgegeben mit Produkten in
ASC-Reihenfolge sortiert.

**Urteil**: **BLOCKIERT** — Richtung wird genau auf `'ASC'` oder `'DESC'` abgebildet, nie interpoliert.

---

### ATK-06 — UNION-Injection via Suchabfrage

**Angriff**: Ein `UNION SELECT` einschleusen, um Daten zu exfiltrieren.

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**Beobachtet**: Die vollständige Injection-Zeichenkette wird als LIKE-Parameterwert gebunden. Das
`UNION SELECT` wird als wörtlicher Text in den Spalten `name` und `description` gesucht. Es werden
keine Benutzerdaten zurückgegeben.

**Urteil**: **BLOCKIERT** — parametrisierte Abfrage verhindert UNION-Injection.

---

### ATK-07 — ID-Injection via Pfadparameter

**Angriff**: SQL via den Pfadparameter einschleusen.

```
GET /products/1;%20DROP%20TABLE%20products;
```

**Beobachtet**: Der Pfadparameter `{id}` wird durch `(int) $params['id']` auf int gecastet. Das SQL
wird zu `WHERE id = 1` — das Injection-Suffix wird durch den Cast abgeschnitten. Die Tabelle wird
nicht gedroppt.

**Urteil**: **BLOCKIERT** — `(int)`-Cast schneidet beim ersten Nicht-Ziffer-Zeichen ab.

---

### ATK-08 — Boolean-basierte blinde Injection via Suche

**Angriff**: Daten über boolesche Bedingungen herauslecken.

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**Beobachtet**: Beide Zeichenketten werden als LIKE-Parameter gebunden. Beide geben Produkte zurück,
deren Name oder Beschreibung den wörtlichen Text `' AND '1'='1` enthält. Keine der Abfragen ändert
die SQL-WHERE-Logik. Beide geben dasselbe (leere) Ergebnisset zurück.

**Urteil**: **BLOCKIERT** — parametrisierte Bindung verhindert boolesche Injection.

---

### ATK-09 — Second-Order Injection: gespeicherter Payload wird später abgerufen

**Angriff**: Produkt mit SQL im Namen erstellen, dann alle Produkte abrufen.

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**Beobachtet**: Das `INSERT` verwendet parametrisiertes `?` — der Injection-Payload wird als Literaltext
gespeichert. Die `SELECT *`- und `LIKE`-Abfragen verwenden ebenfalls parametrisierte Abfragen. Der Payload
wird als Zeichenkettenwert zurückgegeben, nie als SQL ausgeführt.

**Urteil**: **BLOCKIERT** — alle Lese- und Schreibpfade verwenden parametrisierte Abfragen.

---

### ATK-10 — LIKE-Metazeichen-Flut: `%`-Suche

**Angriff**: `?q=%` senden, um alle Produkte zu treffen und eine beabsichtigte Leersuche zu umgehen.

```
GET /products?q=%25   (URL-dekodiert: %)
```

**Beobachtet**: `$q = '%'` wird als LIKE-Parameter gebunden. `LIKE '%' || '%' || '%'` = `LIKE '%%%'`
trifft jede Zeile. Alle Produkte werden zurückgegeben.

**Urteil**: **EXPONIERT** — `%` und `_` in Benutzereingaben werden nicht escaped. Eine Suche nach `%`
trifft alles; eine Suche nach `_` trifft jedes einzelne Zeichen. LIKE-Metazeichen escapen oder
das Verhalten als beabsichtigt dokumentieren.

---

### ATK-11 — Null-Byte-Injection

**Angriff**: Ein Null-Byte in die Suchabfrage einbetten.

```
GET /products?q=widget%00extra
```

**Beobachtet**: PHPs `?`-Bindung übergibt die rohe Zeichenkette einschließlich des Null-Bytes an
SQLites parametrisierte Abfrage. SQLite behandelt das Null-Byte als Teil der Zeichenkette. `LIKE '%widget\0extra%'`
trifft keine normalen Produktnamen. Keine Injection tritt auf.

**Urteil**: **BLOCKIERT** — parametrisierte Abfragen behandeln Null-Bytes als wörtlichen Zeichenketteninhalt.

---

### ATK-12 — Gestapelte Abfragen (Multi-Statement-Injection)

**Angriff**: Eine zweite Anweisung nach einem Semikolon einschleusen.

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**Beobachtet**: PDO führt nur eine Anweisung pro `query()`/`prepare()`-Aufruf aus — gestapelte Abfragen
werden standardmäßig nicht unterstützt. Selbst wenn PDO mehrere Anweisungen erlaubte, wird der Wert als
Parameter gebunden (nicht interpoliert). Das eingeschleuste INSERT wird als wörtlicher LIKE-Suchtext gespeichert.

**Urteil**: **BLOCKIERT** — parametrisierte Bindung + PDO-Single-Statement-Modus verhindern gestapelte Abfragen.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---|---|
| ATK-01 | Klassische SELECT-Injection via `?q=` | BLOCKIERT |
| ATK-02 | DROP TABLE via Suche | BLOCKIERT |
| ATK-03 | ORDER BY unbekannte Spalte | BLOCKIERT |
| ATK-04 | ORDER BY Unterabfrage-Injection | BLOCKIERT |
| ATK-05 | Sortierrichtungs-Injection | BLOCKIERT |
| ATK-06 | UNION SELECT via Suche | BLOCKIERT |
| ATK-07 | ID-Injection via Pfadparam | BLOCKIERT |
| ATK-08 | Boolean-basierte blinde Injection | BLOCKIERT |
| ATK-09 | Second-Order-Injection | BLOCKIERT |
| ATK-10 | LIKE-Metazeichen-Flut (`%`) | EXPONIERT |
| ATK-11 | Null-Byte-Injection | BLOCKIERT |
| ATK-12 | Gestapelte Abfragen | BLOCKIERT |

**Echte Schwachstellen vor der Produktion beheben**:
1. **ATK-10** — LIKE-Metazeichen (`%`, `_`, `\`) vor der Bindung escapen, um Wildcard-Flutung zu verhindern.

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

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — Explizites DTO-Whitelisting als umfassenderes Abwehrmuster
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 als Alternative zu LIKE für Volltextsuche
- [`jwt-authentication.md`](jwt-authentication.md) — VULN-Bewertung einschließlich SQL-Injection (V-08)
