---
title: SQL injection defense
category: security
tags: [sql-injection, pdo, validation]
difficulty: intermediate
related: [mass-assignment, validate-unicode-input]
---

# SQL injection defense

NENE2's database methods (`execute`, `insert`, `fetchOne`, `fetchAll`) use PDO prepared statements internally. Any value passed in the `$parameters` array is bound as a PDO parameter — never interpolated into the SQL string.

## Safe by default: value parameters

```php
// All values go through PDO binding — injection-safe regardless of content
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// LIKE search — wildcard in SQL literal, value bound separately
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

Classic payloads (`' OR '1'='1`, `'; DROP TABLE products; --`, `UNION SELECT ...`) become literal search strings because PDO never interpolates them into SQL.

## The ORDER BY footgun — whitelist required

**PDO cannot parameterize column names or SQL structural elements.** `ORDER BY ?` does not work — it binds a literal string value, not a column reference.

If a developer puts user input directly into `ORDER BY`, it becomes an injection vector:

```php
// UNSAFE — never do this
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- executes the DROP
```

**Always validate against an explicit whitelist before interpolating column names:**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    // Only ASC or DESC — normalize, never interpolate raw user input
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

The same principle applies to any SQL structural element: table names, column names in `GROUP BY`, `HAVING`, `INSERT INTO ... (col1, col2)` — none of these can be bound as PDO parameters. Whitelist-validate before interpolating.

## IN clause with variable length

PDO does not support binding a variable-length list directly. Build the placeholder list explicitly:

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## Summary

| Input type | Safe method |
|---|---|
| Filter value (`WHERE col = ?`) | `?` placeholder in `$parameters` |
| LIKE value | `'%' \|\| ? \|\| '%'` — value in `$parameters` |
| ORDER BY column | Whitelist `in_array` + interpolate only after passing |
| ORDER direction | Normalize to literal `'ASC'` or `'DESC'` |
| IN list | Build `?` placeholders from `count()`, spread array as params |
| Table/column name | Whitelist only — never accept from user input |
