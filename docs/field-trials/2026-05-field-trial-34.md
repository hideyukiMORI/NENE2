# Field Trial 34 — Coupon Management API (couponlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/couponlog/`
**NENE2 version**: 1.5.17
**Theme**: Nullable fields, atomic coupon redemption, duplicate detection, v1.5.17 float encoding validation

## Overview

Built a coupon/discount code management API with atomic redemption (check active + not expired + not exhausted, then increment used_count in a transaction), nullable `expires_at`, unlimited vs limited-use coupons, and redemption history.

The FT also validates the `JSON_PRESERVE_ZERO_FRACTION` fix from v1.5.17: a dedicated test asserts that `discount_value: 10.0` appears as `10.0` (not `10`) in the raw JSON response body.

## Endpoints Implemented

- `POST /coupons` — create coupon (code, type, discount_value, max_uses?, is_active?, expires_at?)
- `GET /coupons` — list active coupons (not expired, not exhausted), paginated
- `GET /coupons/{code}` — show coupon by code
- `POST /coupons/{code}/redeem` — atomic redeem; 409 on inactive/expired/exhausted
- `GET /coupons/{code}/redemptions` — redemption history, paginated

## Test Results

25 tests, 54 assertions — all pass after 1 PHPStan fix.

---

## Frictions Found

### Friction 1 — PHPStan level 8: `isset()` makes subsequent `!== null` always true [LOW]

**Symptom**: PHPStan 8 reported:

```
Strict comparison using !== between mixed and null will always evaluate to true.
Type null has already been eliminated from mixed.
```

for this pattern in `hydrateCoupon()`:

```php
isset($row['expires_at']) && $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
```

**Root cause**: `isset()` returns true only when the value is not null. After `isset($row['expires_at'])` is true, PHPStan narrows the type to exclude null, so the subsequent `!== null` is always true.

**Fix**: Remove the redundant null check:

```php
isset($row['expires_at']) ? (string) $row['expires_at'] : null,
```

**NENE2 impact**: Low — this is PHPStan level 8 strictness, not a framework issue. Good to know for documentation.

---

### Friction 2 — Duplicate coupon code produces 500 (unhandled DomainException) [MEDIUM]

**Symptom**: Creating a coupon with an already-used code throws `\DomainException("Coupon code already exists: …")`. Since no `DomainExceptionHandler` for this exception is registered in `RuntimeApplicationFactory`, it falls through to the generic 500 handler.

**Workaround**: Add a `DuplicateCouponCodeException` with a registered handler (same as `DuplicateSkuException` in inventorylog).

**Root cause**: The pattern of catching `DatabaseConnectionException → UNIQUE constraint failed → throw DomainException` requires the caller to register a handler for the new exception type. This is correct behaviour, but when you forget to register the handler, the error surface changes from 409 → 500 with no obvious clue.

**NENE2 impact**: Could add a hint in `docs/howto/add-domain-exception-handler.md`: if a domain exception produces 500 instead of your expected error code, check that you've registered its handler in `domainExceptionHandlers`.

---

## v1.5.17 Float Encoding Validation ✓

`testDiscountValueEncodedAsFloat` confirms that `"discount_value": 10.0` (not `10`) appears in the raw JSON response, validating the `JSON_PRESERVE_ZERO_FRACTION` fix.

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Add hint to `add-domain-exception-handler.md`: unregistered handler produces 500 | Low |

No version bump needed — documentation-only change is a low-priority addition to an existing howto; will bundle with the next substantive change.
