# How-to: Coupon / Discount Code Redemption API

This guide shows how to build a coupon redemption system with usage limits and expiry using NENE2.
Pattern demonstrated by the **couponlog** field trial (FT218).

## Features

- Create coupon codes with discount amount, usage limit, and expiry (admin only)
- Optional auto-generation of random codes (`bin2hex(random_bytes(6))`)
- One redemption per user per coupon (`UNIQUE(coupon_id, user_id)`)
- Usage limit enforcement (`max_uses`)
- Expiry checking against current UTC time
- Admin-only redemption listing

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
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
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/coupons` | Admin | Create coupon |
| `GET` | `/coupons/{code}` | Public | Get coupon info |
| `POST` | `/coupons/{code}/redeem` | User | Redeem coupon |
| `GET` | `/coupons/{code}/redemptions` | Admin | List redemptions |

## Code Validation

Coupon codes use a strict pattern to prevent injection:

```php
/** Coupon code: uppercase alphanumeric, 4–32 chars */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

Path parameter normalized to uppercase before validation:

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## Redemption Logic

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // Check expiry
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // Check usage limit
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // Check per-user limit
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // Record + increment counter
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

Route handler uses `match` expression for clean branching:

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## Auto-Generated Codes

When no `code` is provided in the request body, one is generated:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 uppercase hex chars
}
```

## Security Patterns

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` before `hash_equals()`
- **Code pattern**: `ctype_digit()` equivalent for codes — regex `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`**: Strict type check for `discount` and `max_uses` — rejects floats
- **ISO 8601 expiry**: Regex validation + lexicographic comparison (UTC strings)
- **Atomic increment**: `UPDATE SET used_count = used_count + 1` prevents race conditions
- **UNIQUE constraint**: Database-level safety net for duplicate prevention
