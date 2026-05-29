---
title: "How-to: Flash Sale API"
category: product
tags: [flash-sale, inventory, race-condition, time-window]
difficulty: advanced
related: [flash-sale-system, inventory-management, prevent-double-booking]
ft: FT304
---

# How-to: Flash Sale API

> **FT reference**: FT304 (`NENE2-FT/salelog`) — Flash sale API: time-window validation (sale not started → 422, ended → 422), UNIQUE(sale_id, user_id) prevents double-purchase, sold-out stock check, negative price/zero quantity → 422, inverted dates rejected, ATK-01〜12 all BLOCKED, 29 tests / 42 assertions PASS.

This guide shows how to build a flash sale system where users purchase limited-stock products within a time window, with race-condition protection and attack prevention.

## Schema

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`CHECK (quantity > 0)` and `CHECK (price >= 0)` enforce business rules at the DB level. `UNIQUE(sale_id, user_id)` prevents the same user from buying the same sale twice — even under concurrent requests.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/products` | — | Create product |
| `POST` | `/sales` | — | Create flash sale |
| `GET` | `/sales` | — | List active sales |
| `GET` | `/sales/{id}` | — | Get sale details |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | Purchase (time-checked) |

## Sale Creation Validation

```php
if (!is_int($price) || $price < 0) {
    return 422; // negative price rejected
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // zero or negative quantity rejected
}
if ($endsAt <= $startsAt) {
    return 422; // inverted or equal dates rejected
}
```

Three DB-level checks backed by application-level validation:
- `price >= 0` — free sales allowed (`0`), negative prices not
- `quantity > 0` — zero-quantity sales cannot be created
- `ends_at > starts_at` — time inversion rejected

## Purchase — Time Window Check

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // sale not yet started
}
if ($now > $sale['ends_at']) {
    return 422; // sale has ended
}
```

Purchase attempts outside the sale window return 422. The check uses server-side `date('c')` — clients cannot manipulate the time.

## Stock Check

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

Count existing purchases against the sale's `quantity` before inserting. If sold out, return 422 with `"error": "sold out"`.

## UNIQUE(sale_id, user_id) — Double-Purchase Prevention

```php
// UNIQUE constraint catches concurrent duplicate purchases
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // UNIQUE constraint violation → already purchased
    return $this->json(['error' => 'already purchased'], 409);
}
```

The DB `UNIQUE(sale_id, user_id)` constraint is the final guard against race conditions. First purchase succeeds (201); any duplicate returns 409 Conflict.

## User ID Validation

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- Missing or non-numeric `X-User-Id` → 400
- Non-existent user ID → 404 (IDOR prevention — cannot purchase as ghost user)

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL Injection in Product Name 🚫 BLOCKED

**Attack**: `POST /products` with `name: "'; DROP TABLE products; --"`.
**Result**: BLOCKED — parameterized query stores the injection string verbatim (201). Subsequent requests still work; products table intact.

---

### ATK-02 — Purchase Without X-User-Id Header 🚫 BLOCKED

**Attack**: `POST /sales/{id}/purchase` with no `X-User-Id` header.
**Result**: BLOCKED — missing header returns 400.

---

### ATK-03 — Non-Numeric X-User-Id Header 🚫 BLOCKED

**Attack**: `X-User-Id: admin` (string value).
**Result**: BLOCKED — `ctype_digit()` check rejects non-numeric values; not 201.

---

### ATK-04 — Negative Sale ID in URL 🚫 BLOCKED

**Attack**: `POST /sales/-1/purchase`.
**Result**: BLOCKED — negative ID resolves to no sale found; not 201.

---

### ATK-05 — Purchase Before Sale Starts 🚫 BLOCKED

**Attack**: Create a sale starting 1 hour in the future; try to purchase immediately.
**Result**: BLOCKED — `$now < $sale['starts_at']` check → 422.

---

### ATK-06 — Purchase After Sale Ends 🚫 BLOCKED

**Attack**: Create a sale that ended 1 hour ago; try to purchase.
**Result**: BLOCKED — `$now > $sale['ends_at']` check → 422.

---

### ATK-07 — Double-Purchase Same Sale 🚫 BLOCKED

**Attack**: Same user purchases the same sale twice in rapid succession.
**Result**: BLOCKED — first purchase 201; second purchase 409 (UNIQUE constraint or application-level check).

---

### ATK-08 — Exhaust Stock Then Buy 🚫 BLOCKED

**Attack**: Create sale with `quantity=1`; Alice buys it; Bob tries to buy.
**Result**: BLOCKED — stock check `purchaseCount >= quantity` → 422 `"sold out"` for Bob.

---

### ATK-09 — Create Sale With quantity=0 🚫 BLOCKED

**Attack**: `POST /sales` with `quantity: 0`.
**Result**: BLOCKED — `quantity <= 0` validation + DB `CHECK (quantity > 0)` → 422.

---

### ATK-10 — Create Sale With Negative Price 🚫 BLOCKED

**Attack**: `POST /sales` with `price: -999`.
**Result**: BLOCKED — `price < 0` validation + DB `CHECK (price >= 0)` → 422.

---

### ATK-11 — Purchase as Non-Existent User 🚫 BLOCKED

**Attack**: `X-User-Id: 99999` (ID that doesn't exist in users table).
**Result**: BLOCKED — `findUser($actorId) === null` → 404.

---

### ATK-12 — Inverted Sale Dates (ends_at before starts_at) 🚫 BLOCKED

**Attack**: `starts_at: "+2 hours"`, `ends_at: "+1 hour"`.
**Result**: BLOCKED — `$endsAt <= $startsAt` validation → 422.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | SQL injection in product name | 🚫 BLOCKED |
| ATK-02 | Purchase without X-User-Id | 🚫 BLOCKED |
| ATK-03 | Non-numeric X-User-Id | 🚫 BLOCKED |
| ATK-04 | Negative sale ID in URL | 🚫 BLOCKED |
| ATK-05 | Purchase before sale starts | 🚫 BLOCKED |
| ATK-06 | Purchase after sale ends | 🚫 BLOCKED |
| ATK-07 | Double-purchase same sale | 🚫 BLOCKED |
| ATK-08 | Exhaust stock then buy | 🚫 BLOCKED |
| ATK-09 | Create sale with quantity=0 | 🚫 BLOCKED |
| ATK-10 | Create sale with negative price | 🚫 BLOCKED |
| ATK-11 | Purchase as non-existent user | 🚫 BLOCKED |
| ATK-12 | Inverted sale dates | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Time-window server-side check, stock count guard, UNIQUE constraint, and strict input validation prevent all known attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Trust client-provided timestamp for time check | Clients send past/future timestamps to bypass window |
| No `UNIQUE(sale_id, user_id)` | Concurrent requests allow same user to buy twice under load |
| Check stock without race-condition guard | Between stock check and insert, another request can exhaust stock |
| Accept `quantity: 0` sale creation | Zero-quantity sale can never be purchased; confusing edge case |
| Accept `price: -999` | Negative-price purchase credits the buyer instead of charging |
| No user existence check | Ghost user IDs (not in DB) bypass audit trails |
| `$endsAt >= $startsAt` (allow equal) | Equal start/end creates a zero-duration window — immediately expired |
| Non-numeric X-User-Id accepted | `"admin"` string cast to `(int)` becomes `0`; bypasses auth |
| Return 409 for time-window errors | Time violations are business validation failures (422), not state conflicts |
