---
title: "How-To: Asset Check-out / Check-in Management"
category: product
tags: [asset-management, checkout, exclusive-lock, audit-log]
difficulty: intermediate
related: [audit-trail, resource-reservation, optimistic-locking]
---

# How-To: Asset Check-out / Check-in Management

Demonstrates exclusive asset hold tracking with an append-only audit log.
Field trial: FT194 (`../NENE2-FT/assetlog/`).

---

## Pattern summary

| Concern | Approach |
|---|---|
| Exclusive hold | `holder_id INTEGER` — NULL = available, non-null = held |
| Checkout conflict | 409 if `holder_id IS NOT NULL` before updating |
| Wrong-holder checkin | 403 if `holder_id != userId` |
| Audit log | Append-only `asset_history` rows on every state change |
| IDOR prevention | Public API hides `holder_id`; admin key required to see it |
| Admin key | `hash_equals()` constant-time comparison, fail-closed on empty key |
| User identity | `X-User-Id` header; `ctype_digit()` + length guard, no regex |

---

## Routes

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | Create asset |
| `GET` | `/assets` | — | List all assets |
| `GET` | `/assets/{id}` | — | Get single asset |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | Check out asset |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | Check in asset |
| `GET` | `/assets/{id}/history` | — | Audit history |

---

## Database schema

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = available
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## Exclusive checkout pattern

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

The `WHERE holder_id IS NULL` guard prevents double-checkout even under concurrent requests
(SQLite serialises writes; MySQL/PgSQL need a transaction or `SELECT FOR UPDATE`).

---

## IDOR prevention

```php
// Public response — no holder_id
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// Admin response — includes holder_id
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

The handler checks `isAdmin()` and chooses the correct projection:

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## Admin key (fail-closed)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // no key configured → deny
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

---

## User ID validation

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` is O(n) and ReDoS-safe. Length cap prevents integer overflow.

---

## Error mapping

| Repository result | HTTP status |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## Testing notes

- `AppFactory::create(?PDO, ?string)` accepts in-memory SQLite for unit tests.
- `withParsedBody($body)` must be called on test requests — Nyholm PSR-7 does not auto-parse JSON.
- Public list / get assertions verify `holder_id` key is absent (`assertArrayNotHasKey`).
- Lifecycle test: checkout → conflict → checkin → re-checkout by different user.
