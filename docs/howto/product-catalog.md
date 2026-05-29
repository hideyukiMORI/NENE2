---
title: "How-to: Product Catalog API (ATK-01~12)"
category: product
tags: [catalog, soft-delete, search, admin-auth, attack-hardening]
difficulty: intermediate
related: [product-review-system, shopping-cart-api, soft-delete]
---

# How-to: Product Catalog API (ATK-01~12)

This guide demonstrates a product catalog API with admin-only write operations, keyword search, and soft delete — covering ATK-01~12 cracker attack vectors.

## Pattern Overview

- Catalog reads are public; writes (create, delete) require admin (`X-Admin-Key`).
- SKUs are uppercase alphanumeric with hyphens (`/\A[A-Z0-9\-]{1,32}\z/`).
- Soft delete (`active = 0`) hides products without losing history.
- Keyword search uses `LIKE` with length guard to prevent keyword bombs.

## Schema

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01: SQL Injection in Search Keyword

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

The `%` wildcard is part of the literal value passed to a parameterized query — no interpolation occurs.

## ATK-02: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Empty admin key → always 403. Wrong key → `hash_equals()` avoids timing leaks.

## ATK-03: Integer Overflow in Product ID

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

A 20-digit ID string exceeds 18 chars and is rejected before any `(int)` cast or DB query.

## ATK-04: Negative ID

`ctype_digit()` on `-1` fails (non-digit char) → 404.

## ATK-05: Float Price

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` returns `false` — float prices are rejected.

## ATK-06: SKU Injection

The SKU regex `/\A[A-Z0-9\-]{1,32}\z/` rejects `; DROP TABLE`, quotes, spaces, and lowercase. Only the exact format is accepted.

## ATK-07: Wildcard Search Injection

`%` in a search keyword is treated as a SQL LIKE wildcard — it matches everything. This is intentional (users can search all). The LIKE is parameterized so `%; DROP TABLE products; --` is not executed as SQL:

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

The result is just a broader LIKE match, not an injection.

## ATK-08: Double Delete

The repository's `delete()` checks `findById()` (active=1 only) first. A soft-deleted product returns null → 404 on second delete.

## ATK-09: SKU Too Long

Regex quantifier `{1,32}` rejects SKUs longer than 32 chars before reaching the DB.

## ATK-10: Wrong Admin Key

`hash_equals()` comparison always takes the same time regardless of how many characters match.

## Keyword Length Guard

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

Prevents sending a 10 MB LIKE pattern to the database.

## Soft Delete

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

All reads include `WHERE active = 1`. Deleted products become invisible without physical removal.

## Routes

```
POST   /products      Create product (admin only)
GET    /products      List/search products (public)
GET    /products/{id} Get product (public)
DELETE /products/{id} Soft-delete product (admin only)
```

## See Also

- FT212 source: `../NENE2-FT/productlog/`
- Related: `docs/howto/inventory-management.md` (FT203, SKU-based stock)
- Related: `docs/howto/session-token-management.md` (FT208, also ATK)
