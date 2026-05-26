# How-to: SQL Injection Defence

> **FT reference**: FT264 (`NENE2-FT/injectionlog`) — SQL injection defence: parameterized queries, LIKE injection, ORDER BY allowlist
> **ATK**: FT264 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates the three main SQL injection vectors in a PHP API — value injection, LIKE wildcard
injection, and ORDER BY column injection — and the correct defence for each. Includes a full
cracker-mindset attack assessment.

---

## Routes

| Method   | Path            | Description                              |
|----------|-----------------|------------------------------------------|
| `GET`    | `/products`     | List/search products (filterable, sorted)|
| `POST`   | `/products`     | Create a product                         |
| `GET`    | `/products/{id}`| Get a single product                     |
| `DELETE` | `/products/{id}`| Delete a product                         |

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

## The three SQL injection surfaces

### 1. Value injection: parameterized queries

```php
// ❌ String interpolation — injectable
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ Parameterized — the driver escapes all values
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

PDO's `?` placeholder binds the value as a typed parameter. The value is never interpolated
into the SQL string. An attacker who sends `id = "1; DROP TABLE products; --"` has their
entire input stored as a literal string binding — the SQL is not modified.

### 2. LIKE wildcard injection: parameterized wildcards

```php
// ❌ Interpolated LIKE — injectable AND wildcard-escaped
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ Parameterized wildcard — the ? value is bound after the || concatenation
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` is standard SQL string concatenation (SQLite, PostgreSQL). The `?` value
is bound as a parameter — the `%` wildcards are literals in the SQL string, not from user input.

**LIKE metacharacter escape**: `%` and `_` within the user input `$q` are NOT escaped in this
implementation. A search for `%` would match everything. For production, escape LIKE metacharacters:

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. ORDER BY injection: column allowlist

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // safe: allowlisted column + whitelisted direction

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` cannot use parameterized placeholders — the column name must be interpolated.
The correct defence is an explicit allowlist: only values in `ALLOWED_SORT_FIELDS` may appear
in the SQL string. Any other value throws an exception (400 in the controller).

`sortDir` is mapped to exactly `'ASC'` or `'DESC'` — user input is never directly interpolated.

---

## ATK — Cracker-mindset attack test (FT264)

### ATK-01 — Classic SELECT injection via GET parameter

**Attack**: Inject SQL via the search query `?q=' OR '1'='1`.

```
GET /products?q=' OR '1'='1
```

**Observed**: `$q` is bound as a `?` parameter in `LIKE '%' || ? || '%'`. The entire string
`' OR '1'='1` is treated as a literal text value to match. No additional rows are returned.

**Verdict**: **BLOCKED** — parameterized LIKE prevents value injection.

---

### ATK-02 — DROP TABLE injection via search

**Attack**: Inject a destructive statement.

```
GET /products?q='; DROP TABLE products; --
```

**Observed**: The payload is bound as a LIKE pattern. `'; DROP TABLE products; --` is searched
as literal text. The table is not dropped.

**Verdict**: **BLOCKED** — parameterized queries cannot execute injected statements.

---

### ATK-03 — ORDER BY column injection: arbitrary column

**Attack**: Inject an unrecognized sort column.

```
GET /products?sort=password
```

**Observed**: `in_array('password', self::ALLOWED_SORT_FIELDS, true)` returns `false`.
`InvalidSortFieldException` is thrown. The controller catches it and returns 400.

**Verdict**: **BLOCKED** — column allowlist rejects unknown column names.

---

### ATK-04 — ORDER BY injection: subquery injection

**Attack**: Inject a subquery as the sort column.

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**Observed**: The decoded value `(SELECT name FROM users LIMIT 1)` is not in `ALLOWED_SORT_FIELDS`.
`InvalidSortFieldException` thrown. 400 returned.

**Verdict**: **BLOCKED** — allowlist rejects any value not in the known column list, including subqueries.

---

### ATK-05 — ORDER BY injection: direction tampering

**Attack**: Inject SQL via the sort direction parameter.

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**Observed**: `strtolower($sortDir) === 'desc'` is `false` for the injected value. The direction
falls through to `'ASC'`. The injected SQL is never interpolated. 200 returned with products
ordered ASC.

**Verdict**: **BLOCKED** — direction is mapped to exactly `'ASC'` or `'DESC'`, never interpolated.

---

### ATK-06 — UNION injection via search query

**Attack**: Inject a `UNION SELECT` to exfiltrate data.

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**Observed**: The full injection string is bound as a LIKE parameter value. The `UNION SELECT`
is searched as literal text in the `name` and `description` columns. No user data is returned.

**Verdict**: **BLOCKED** — parameterized query prevents UNION injection.

---

### ATK-07 — ID injection via path parameter

**Attack**: Inject SQL via the path parameter.

```
GET /products/1;%20DROP%20TABLE%20products;
```

**Observed**: The path parameter `{id}` is cast to `int` by `(int) $params['id']`. The SQL
becomes `WHERE id = 1` — the injection suffix is truncated by the cast. The table is not dropped.

**Verdict**: **BLOCKED** — `(int)` cast truncates at the first non-digit character.

---

### ATK-08 — Boolean-based blind injection via search

**Attack**: Leak data via boolean conditions.

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**Observed**: Both strings are bound as LIKE parameters. Both return products whose name or
description contains the literal text `' AND '1'='1`. Neither query modifies the SQL WHERE
logic. Both return the same (empty) result set.

**Verdict**: **BLOCKED** — parameterized binding prevents boolean injection.

---

### ATK-09 — Second-order injection: stored payload retrieved later

**Attack**: Create a product with a name containing SQL, then search for all products.

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**Observed**: The `INSERT` uses parameterized `?` — the injection payload is stored as literal
text. The `SELECT *` and `LIKE` queries also use parameterized queries. The payload is returned
as a string value, never executed as SQL.

**Verdict**: **BLOCKED** — all read and write paths use parameterized queries.

---

### ATK-10 — LIKE metacharacter flood: `%` search

**Attack**: Send `?q=%` to match all products, bypassing a intended empty-search default.

```
GET /products?q=%25   (URL-decoded: %)
```

**Observed**: `$q = '%'` is bound as a LIKE parameter. `LIKE '%' || '%' || '%'` = `LIKE '%%%'`
which matches every row. All products are returned.

**Verdict**: **EXPOSED** — `%` and `_` in user input are not escaped. A search for `%` matches
everything; a search for `_` matches any single character. Escape LIKE metacharacters or document
the behaviour as intentional.

---

### ATK-11 — NULL byte injection

**Attack**: Embed a null byte in the search query.

```
GET /products?q=widget%00extra
```

**Observed**: PHP's `?` binding passes the raw string including the null byte to SQLite's
parameterized query. SQLite treats the null byte as part of the string. `LIKE '%widget\0extra%'`
does not match normal product names. No injection occurs.

**Verdict**: **BLOCKED** — parameterized queries handle null bytes as literal string content.

---

### ATK-12 — Stacked queries (multi-statement injection)

**Attack**: Inject a second statement after a semicolon.

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**Observed**: PDO executes only one statement per `query()`/`prepare()` call — stacked queries
are not supported by default. Even if PDO allowed multiple statements, the value is bound as a
parameter (not interpolated). The injected INSERT is stored as literal LIKE search text.

**Verdict**: **BLOCKED** — parameterized binding + PDO single-statement mode prevent stacked queries.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---|---|
| ATK-01 | Classic SELECT injection via `?q=` | BLOCKED |
| ATK-02 | DROP TABLE via search | BLOCKED |
| ATK-03 | ORDER BY unknown column | BLOCKED |
| ATK-04 | ORDER BY subquery injection | BLOCKED |
| ATK-05 | Sort direction injection | BLOCKED |
| ATK-06 | UNION SELECT via search | BLOCKED |
| ATK-07 | ID injection via path param | BLOCKED |
| ATK-08 | Boolean-based blind injection | BLOCKED |
| ATK-09 | Second-order injection | BLOCKED |
| ATK-10 | LIKE metacharacter flood (`%`) | EXPOSED |
| ATK-11 | Null byte injection | BLOCKED |
| ATK-12 | Stacked queries | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-10** — Escape LIKE metacharacters (`%`, `_`, `\`) before binding to prevent wildcard flooding.

---

## Defence summary

| Surface | Vulnerable pattern | Safe pattern |
|---|---|---|
| Value in WHERE | `WHERE id = {$id}` | `WHERE id = ?` with `[$id]` |
| LIKE search | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| ORDER BY column | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + interpolate |
| ORDER BY direction | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| Path parameter ID | `WHERE id = {$id}` | `(int) $id` + parameterized |

---

## Related howtos

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explicit DTO whitelisting as a broader defence pattern
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 as an alternative to LIKE for full-text search
- [`jwt-authentication.md`](jwt-authentication.md) — VULN assessment including SQL injection (V-08)
