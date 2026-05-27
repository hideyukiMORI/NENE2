# How-to: API Usage Metering & Quota Management

> **FT reference**: FT321 (`NENE2-FT/meterlog`) — Per-user daily quota management, machine-key protected usage recording, per-endpoint breakdown, IDOR protection, remaining-never-negative guarantee, 24 tests / 92 assertions PASS.

This guide shows how to build a usage metering system that tracks API calls per user per day and enforces configurable daily quotas.

## Schema

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## Constants

```php
const DEFAULT_DAILY_LIMIT = 1000;  // applied when no quota row exists
```

## Auth Model

```
POST /quotas               → X-Admin-Key   (quota configuration)
POST /usage                → X-Machine-Key (server-side usage recording)
POST /usage/check          → X-Machine-Key (pre-flight quota check)
GET  /usage/{id}/breakdown → X-User-Id (own) OR X-Admin-Key (any)
```

## Quota Management (Admin)

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// Upsert — updating an existing quota
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// No admin key  → 401
// Wrong key     → 401
// daily_limit <= 0 → 422
```

## Quota Status

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// User with no quota row → DEFAULT_DAILY_LIMIT applied
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **never goes negative**.

## Recording Usage

Called server-side after each successful API request:

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// No machine key → 401
// user_id <= 0   → 422
// empty endpoint → 422
```

## Pre-flight Quota Check

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // within quota
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // exhausted
```

## Usage Breakdown

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR blocked
GET /usage/1/breakdown  X-User-Id: 2        → 403
// Admin can access any user
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// Invalid date
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## Vulnerability Assessment

### V-01 — Quota Admin without Key ✅ SAFE

**Risk**: Unauthenticated caller sets quota to 0 or INT_MAX for any user.
**Finding**: SAFE — `POST /quotas` requires `X-Admin-Key`. Missing or wrong key returns 401.

---

### V-02 — Case/Variant Admin Key Bypass ✅ SAFE

**Risk**: Attacker tries `ADMIN-SECRET`, `admin_secret`, `""` to bypass key check.
**Finding**: SAFE — Exact `hash_equals()` match. All variants return 401.

---

### V-03 — Non-Positive daily_limit ✅ SAFE

**Risk**: `daily_limit=0` or `-1` permanently locks out user.
**Finding**: SAFE — 422 for `daily_limit <= 0`.

---

### V-04 — Usage Recording without Machine Key ✅ SAFE

**Risk**: External caller records fake usage to exhaust quota.
**Finding**: SAFE — `POST /usage` requires `X-Machine-Key`. 401 on missing/wrong key.

---

### V-05 — SQL Injection in Endpoint Field ✅ SAFE

**Risk**: `"'; DROP TABLE usage_events; --"` corrupts DB.
**Finding**: SAFE — Parameterised queries. Injection stored as literal string. Table survives.

---

### V-06 — Non-Positive user_id in Usage ✅ SAFE

**Risk**: `user_id=0/-1` inserts row for non-existent user.
**Finding**: SAFE — 422 for `user_id <= 0`.

---

### V-07 — IDOR on Breakdown ✅ SAFE

**Risk**: User reads another user's endpoint usage patterns.
**Finding**: SAFE — `X-User-Id` compared to path `{id}`. Mismatch → 403. Admin bypasses.

---

### V-08 — Invalid Date in Breakdown ✅ SAFE

**Risk**: Path traversal or impossible date in `date=` param causes crash or SQL error.
**Finding**: SAFE — `/^\d{4}-\d{2}-\d{2}$/` + `checkdate()` validation. Invalid → 422.

---

### V-09 — Remaining Quota Goes Negative ✅ SAFE

**Risk**: Negative `remaining` shown to clients when usage exceeds reduced quota.
**Finding**: SAFE — `remaining = max(0, $daily_limit - $used)`.

---

### V-10 — Empty Endpoint String ✅ SAFE

**Risk**: Empty endpoint creates unusable breakdown rows.
**Finding**: SAFE — 422 for `endpoint === ''`.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Quota admin without key | ✅ SAFE |
| V-02 | Key case/variant bypass | ✅ SAFE |
| V-03 | Non-positive daily_limit | ✅ SAFE |
| V-04 | Usage without machine key | ✅ SAFE |
| V-05 | SQL injection in endpoint | ✅ SAFE |
| V-06 | Non-positive user_id | ✅ SAFE |
| V-07 | IDOR on breakdown | ✅ SAFE |
| V-08 | Invalid date format | ✅ SAFE |
| V-09 | Negative remaining quota | ✅ SAFE |
| V-10 | Empty endpoint string | ✅ SAFE |

**10 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Let `remaining` go negative | Confusing negative numbers; gate logic breaks |
| No machine-key on usage recording | Any client inflates/deflates another user's quota |
| No IDOR check on breakdown | Endpoint usage patterns leak to unauthorized users |
| Record usage before quota check | Rejected calls still consume quota |
| Allow `daily_limit=0` | User permanently locked out from start |
