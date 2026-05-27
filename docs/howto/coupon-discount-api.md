# How-to: Coupon Discount Code API

> **FT reference**: FT302 (`NENE2-FT/couponlog`) — Coupon discount code API: admin-only creation with `X-Admin-Key` (hash_equals), CODE_PATTERN `[A-Z0-9]{4,32}` auto-normalize to uppercase, UNIQUE(coupon_id, user_id) prevents double-redeem, expired/exhausted/duplicate → 409, 26 tests / 50 assertions PASS.

This guide shows how to build a coupon system where admins create discount codes and users redeem them against usage limits and expiry dates.

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- in cents, e.g. 500 = $5.00
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` prevents the same user from redeeming the same coupon twice. The index on `code` speeds up lookups by code string.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/coupons` | `X-Admin-Key` | Create coupon (admin only) |
| `GET` | `/coupons/{code}` | — | Get coupon details |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | Redeem coupon |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | List redemptions (admin only) |

## Admin Authentication — hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` prevents timing-side-channel attacks on the key comparison. If `adminKey` is empty string (misconfigured), `isAdmin()` returns false — fail closed.

## Coupon Code Format — CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- Uppercase alphanumeric only
- 4–32 characters
- `\A` / `\z` anchors (whole-string match, not just substring)

Input codes are normalized to uppercase before validation:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // Auto-generate if not provided
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

A user sending `"summer50"` gets the same coupon as `"SUMMER50"` — the system normalizes to uppercase automatically. `pathCode()` also normalizes path parameters to uppercase, so `GET /coupons/summer50` and `GET /coupons/SUMMER50` resolve to the same coupon.

## Coupon Creation Validation

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount`: strict `is_int()` — floats like `9.99` are rejected
- `max_uses`: defaults to `1` if not provided
- `expires_at`: must match `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}` ISO 8601 prefix

## Redeem — Four Failure Modes

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

All business-rule failures return **409 Conflict** (not 422). The `match` expression is exhaustive — the default branch only fires on a successful `'redeemed'` string from the repository.

## User ID Validation

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` — only pure digit strings accepted (no `-`, `+`, spaces)
- `strlen > 18` — prevents integer overflow on 64-bit PHP (`PHP_INT_MAX` is 19 digits)
- `$id > 0` — zero ID not valid

Returns `null` → 400 Bad Request if the header is missing or malformed.

## UNIQUE(coupon_id, user_id) — Idempotent Redemption

The DB constraint prevents double-redemption at the storage level. The application also checks via the repository before inserting, returning `'already_redeemed'` rather than relying on a DB exception.

Multiple different users can redeem the same coupon (up to `max_uses`). Only the same user trying the same coupon twice is blocked.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Plain `==` for admin key comparison | Timing attack reveals key length / partial matches |
| Empty `adminKey` allows admin access | Misconfigured admin key becomes open access — fail closed |
| Case-sensitive code lookup | `"summer50"` and `"SUMMER50"` treated as different coupons |
| `discount` without `is_int()` | Float `9.99` accepted; fractional cents corrupt ledger |
| 422 for expired/exhausted | These are business-state conflicts, not validation errors — use 409 |
| No UNIQUE(coupon_id, user_id) | Race condition allows same user to redeem twice concurrently |
| No `max_uses` upper bound | Attacker creates coupon with `max_uses: 999999999` for effectively unlimited discount |
| `strlen > N` skip on user ID | Very large integer strings overflow `(int)` cast silently |
| No index on `code` column | Full table scan on every coupon lookup |
| Return redemption list to non-admin | Reveals which user IDs have redeemed — privacy leak |
