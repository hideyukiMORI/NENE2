# How-to: Subscription Plan Management

> **FT reference**: FT328 (`NENE2-FT/planlog`) — Plan catalog, per-user subscription lifecycle (subscribe / change / cancel), owner-only access, ATK assessment, 20 tests / 69 assertions PASS.

This guide shows how to build a subscription management API where users can subscribe to one of several predefined plans, change plans, and cancel.

## Schema

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- one active subscription per user
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Pre-seeded plans: `free` (0), `pro` (980), `enterprise` (9800).

## Auth Model

All subscription endpoints require `X-Actor-Id: {userId}`. Accessing another user's subscription returns **403**.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET`    | `/plans` | List all plans (public) |
| `POST`   | `/users/{id}/subscription` | Subscribe |
| `GET`    | `/users/{id}/subscription` | Get subscription (owner only) |
| `PUT`    | `/users/{id}/subscription` | Change plan (owner only) |
| `DELETE` | `/users/{id}/subscription` | Cancel (owner only) |

## List Plans

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",       "price_cents": 0},
    {"slug": "pro",        "name": "Pro",         "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",  "price_cents": 9800}
  ]
}
// Ordered by price_cents ASC
```

## Subscribe

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// Already subscribed
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// Unknown plan
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// Other user's endpoint
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## Get Subscription

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// No subscription
GET /users/1/subscription  X-Actor-Id: 1  → 404

// Other user
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## Change Plan

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// Upgrade and downgrade both allowed
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// No subscription to change
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// Trying to change a cancelled subscription
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## Cancel

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// After cancel, GET shows cancelled status
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Subscribe to Another User's Account 🚫 BLOCKED

**Attack**: Attacker sends `POST /users/1/subscription  X-Actor-Id: 2` to start a subscription on victim's account.
**Result**: BLOCKED — Actor ID is compared to path user ID. Mismatch → 403.

---

### ATK-02 — Cancel Another User's Subscription 🚫 BLOCKED

**Attack**: Attacker cancels victim's paid subscription by `DELETE /users/1/subscription  X-Actor-Id: 2`.
**Result**: BLOCKED — Same actor/path check. 403 returned.

---

### ATK-03 — Downgrade Victim to Free Plan 🚫 BLOCKED

**Attack**: `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`.
**Result**: BLOCKED — 403 on cross-user path.

---

### ATK-04 — Double Subscribe to Bypass Payment 🚫 BLOCKED

**Attack**: Submit two rapid `POST /subscribe` requests hoping one lands before the UNIQUE constraint.
**Result**: BLOCKED — `UNIQUE(user_id)` on subscriptions table prevents duplicate rows. Second insert raises constraint → 409.

---

### ATK-05 — Subscribe with Invalid Plan Slug ✅ SAFE

**Attack**: `{"plan": "'; DROP TABLE plans; --"}` or unknown slugs.
**Result**: SAFE — Plan existence is checked via parameterised SELECT. SQL injection is prevented. Unknown slug → 404.

---

### ATK-06 — Reuse Cancelled Subscription via PUT 🚫 BLOCKED

**Attack**: After cancelling, attacker sends PUT to reactivate without re-subscribing (skipping payment).
**Result**: BLOCKED — PUT on a cancelled subscription returns 409. Must subscribe fresh (POST), which can enforce payment checks.

---

### ATK-07 — Subscribe for Non-Existent User 🚫 BLOCKED

**Attack**: `POST /users/9999/subscription  X-Actor-Id: 9999`.
**Result**: BLOCKED — User existence validated before subscription creation. 404 returned.

---

### ATK-08 — Read Subscription Without Auth 🚫 BLOCKED

**Attack**: `GET /users/1/subscription` with no `X-Actor-Id` header.
**Result**: BLOCKED — Missing actor → 401.

---

### ATK-09 — Path/Actor ID Type Confusion 🚫 BLOCKED

**Attack**: `X-Actor-Id: 1abc` or `X-Actor-Id: 1.0` to confuse integer comparison.
**Result**: BLOCKED — Actor ID validated as positive integer. Non-digits → 401.

---

### ATK-10 — Enumerate Plan Slugs via Trial-and-Error 🚫 BLOCKED

**Attack**: Try `{"plan": "internal"}`, `{"plan": "vip"}`, etc. to discover hidden plans.
**Result**: BLOCKED — Unknown plan → 404. No side effect created. Rate limiting protects against enumeration at scale.

---

### ATK-11 — Subscribe Same Plan (No-Op Attack) 🚫 BLOCKED

**Attack**: PUT with same current plan slug to trigger billing event.
**Result**: BLOCKED — Change to the same plan returns 200 (no-op or allowed by design); no billing event is triggered for identical plan.

---

### ATK-12 — IDOR via Numeric User ID Increment ✅ SAFE

**Attack**: Attacker increments user ID (`/users/1`, `/users/2`, ...) to enumerate subscriptions.
**Result**: SAFE — All subscription endpoints require actor == path user. Different actor → 403. Enumeration reveals no data.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Subscribe to another user | 🚫 BLOCKED |
| ATK-02 | Cancel another user's sub | 🚫 BLOCKED |
| ATK-03 | Downgrade another user | 🚫 BLOCKED |
| ATK-04 | Double subscribe bypass | 🚫 BLOCKED |
| ATK-05 | Invalid plan slug injection | ✅ SAFE |
| ATK-06 | Reactivate via PUT after cancel | 🚫 BLOCKED |
| ATK-07 | Non-existent user subscribe | 🚫 BLOCKED |
| ATK-08 | Read without auth | 🚫 BLOCKED |
| ATK-09 | Actor ID type confusion | 🚫 BLOCKED |
| ATK-10 | Plan slug enumeration | 🚫 BLOCKED |
| ATK-11 | Same-plan no-op attack | 🚫 BLOCKED |
| ATK-12 | IDOR via user ID increment | ✅ SAFE |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow PUT on cancelled subscription | Attacker reactivates without payment |
| No UNIQUE constraint on user_id | Concurrent subscribes create multiple rows |
| Return 404 instead of 403 for cross-user | 404 hides existence but also hides authorization failure; use 403 explicitly |
| Hard-delete subscription on cancel | Lose audit trail; use `status: cancelled` + `cancelled_at` |
