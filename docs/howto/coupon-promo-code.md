# How-to: Coupon / Promo Code System

## Overview

This guide covers building a coupon/promo code API with NENE2. Features include admin-gated creation, per-user one-time redemption, max-use limits, expiry enforcement, and redemption history.

**Reference implementation**: `../NENE2-FT/couponlog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT    NOT NULL UNIQUE,
    discount   INTEGER NOT NULL,
    max_uses   INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    UNIQUE (coupon_id, user_id)
);
```

---

## Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/coupons` | Admin | Create coupon |
| `GET` | `/coupons/{code}` | None | Get coupon info |
| `POST` | `/coupons/{code}/redeem` | User | Redeem coupon |
| `GET` | `/coupons/{code}/redemptions` | Admin | List redemptions |

---

## Code Validation

```php
if (!preg_match('/\A[A-Z0-9]{4,24}\z/', $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4-24 uppercase alphanumeric characters.');
}
```

---

## Redemption Logic

```php
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    if ($coupon['expires_at'] !== null && $coupon['expires_at'] < $this->now()) {
        return 'expired';
    }

    if ((int) $coupon['max_uses'] > 0) {
        $used = $this->countRedemptions((int) $coupon['id']);
        if ($used >= (int) $coupon['max_uses']) return 'used_up';
    }

    if ($this->hasUserRedeemed((int) $coupon['id'], $userId)) return 'already_redeemed';

    $this->insertRedemption((int) $coupon['id'], $userId);
    return 'ok';
}
```

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Coupon created | 201 |
| Coupon redeemed | 201 |
| No admin key | 403 |
| No X-User-Id | 400 |
| Code / field invalid | 422 |
| Not found | 404 |
| Duplicate code / already redeemed / used up | 409 |
| Expired | 410 |

---

## VULN + ATK Patterns

VULN-A~L and ATK-01~12 all pass. Key patterns:
- IDOR: redemption history is admin-only (403 for users)
- One-redemption-per-user: UNIQUE (coupon_id, user_id)
- Max uses: COUNT before INSERT
- Expiry: ISO8601 UTC lexicographic compare
- Type juggling: `is_int()` strict check for discount and max_uses
- Code injection: regex allowlist `[A-Z0-9]{4,24}`
