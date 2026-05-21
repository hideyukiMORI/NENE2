# Field Trial 137 — Subscription Plan Management (planlog)

**Date**: 2026-05-21  
**Theme**: サブスクリプション・プラン管理 — 加入・変更・解約・再加入  
**Project**: `NENE2-FT/planlog/`  
**NENE2 version**: ^1.5 (released as v1.5.71)  
**Issue**: #778  
**Special**: 通常 FT（特別診断なし）

---

## Overview

This trial implements a subscription management system where users subscribe to plans, upgrade or downgrade, cancel, and re-subscribe. The key challenge was handling re-subscription after cancellation — the `UNIQUE (user_id)` constraint on the subscriptions table means INSERT fails for returning subscribers, requiring UPDATE logic instead.

---

## Implementation Summary

### Schema

Three tables: `users`, `plans`, `subscriptions`. Plans are seeded at schema time (free/pro/enterprise). Subscriptions have a `UNIQUE (user_id)` constraint — one row per user, with `status` tracking the lifecycle.

### API

| Method | Path                           | Status codes      |
|--------|--------------------------------|-------------------|
| POST   | `/users`                       | 201, 422          |
| GET    | `/plans`                       | 200               |
| POST   | `/users/{id}/subscription`     | 201, 403, 404, 409 |
| GET    | `/users/{id}/subscription`     | 200, 403, 404     |
| PUT    | `/users/{id}/subscription`     | 200, 403, 404, 409 |
| DELETE | `/users/{id}/subscription`     | 204, 403, 404     |

### Key design choices

**One row per user** — `UNIQUE (user_id)` on subscriptions. Re-subscription after cancel UPDATEs the existing row (`cancelled_at = NULL, status = 'active'`) rather than inserting a new one.

**409 only for active subscriptions** — `POST /subscription` returns 409 if `status = 'active'`. A cancelled subscription can be reactivated with POST. This was a bug in the initial implementation (see below).

**409 for PUT on cancelled** — `PUT /subscription` requires an active subscription. If `status = 'cancelled'`, the handler returns 409 with a message directing the user to POST instead.

**404 for double-cancel** — `UPDATE ... WHERE status = 'active'` affects 0 rows when already cancelled → handler returns 404. Explicit and predictable.

**Denormalized plan data in response** — `findSubscription()` JOINs plans, so the response includes `plan_slug`, `plan_name`, and `price_cents`. Clients don't need a second request to display the plan details.

---

## Bug Found and Fixed

**testReSubscribeAfterCancel: 409 instead of 201**

Initial implementation returned 409 for any existing subscription (active or cancelled). Re-subscribing after cancel should be allowed.

**Root cause**: The handler checked `$existing !== null` (any subscription) instead of `$existing !== null && $existing['status'] === 'active'`.

**Fix**: 
1. Handler only blocks active subscriptions with 409
2. Repository `subscribe()` checks for an existing cancelled row and UPDATEs instead of INSERTs

---

## Test Results

```
20 tests, 69 assertions — all pass
```

Test coverage:
- Plans list (all 3, ordered by price)
- Subscribe to free/pro
- Subscribe while active → 409
- Subscribe unknown plan → 404
- Subscribe as another user → 403
- Get subscription, get with no subscription (404), get as another user (403)
- Change plan (upgrade + downgrade)
- Change plan with no subscription (404), change plan on cancelled (409)
- Cancel (204), check cancelled status
- Double-cancel → 404
- Cancel with no subscription → 404
- Re-subscribe after cancel → 201 with new plan
- Subscription isolation per user

---

## Developer Experience (DX) Review

### Persona 1: Junko — Junior PHP Developer (2 years experience)

*"The subscription lifecycle diagram in the howto helped a lot. I was confused about when to use POST vs PUT — the error messages in the API ('use PUT to change plan', 'use POST to re-subscribe') guide the client, which I liked. I tripped on the UNIQUE constraint for re-subscribe — I initially tried INSERT and got a constraint error."*

**Rating**: ★★★★☆ (4/5)  
**Learning**: UNIQUE constraint means UPDATE for re-subscribe, not INSERT

---

### Persona 2: Tariq — Mid-level Backend Developer (5 years, first time with NENE2)

*"The one-row-per-user pattern is clean for simple subscription tracking. The `findSubscription()` JOIN for denormalized response is efficient. I like that cancelled subscriptions stay in the table — you get a full audit trail. For production I'd want a separate `subscription_history` table for every plan change."*

**Rating**: ★★★★☆ (4/5)  
**Note**: No change history — every plan change overwrites `started_at` and `plan_id`

---

### Persona 3: Priya — Senior Developer / Tech Lead

*"The `WHERE status = 'active'` guard on the cancel UPDATE is correct — makes it safe to call twice without silent success. The plan seeding in schema.sql is fine for FT scale but production needs a migrations-based approach for plan changes. No price change history either — if you change `price_cents` in plans, existing subscribers' historical pricing is lost."*

**Rating**: ★★★☆☆ (3/5)  
**Concern**: No pricing history, no subscription change audit trail

---

### Persona 4: Markus — DevOps / Platform Engineer

*"Plan seeding is in schema.sql which runs at test setup time — clean for testing. Operationally, adding a new plan requires a migration (ALTER or INSERT INTO plans). The `subscriptions.UNIQUE (user_id)` makes the table simple but limits one active plan at a time — correct for most SaaS billing models. No trial period or grace period logic."*

**Rating**: ★★★★☆ (4/5)  
**Gap**: No trial period, grace period, or scheduled downgrade at billing cycle end

---

### Persona 5: Amara — QA / Test Engineer

*"20 tests is good coverage for 5 endpoints. The bug (re-subscribe after cancel → 409) was caught immediately by the test. The isolation test (`testSubscriptionsArePerUser`) prevents a counting/lookup bug category. Missing: concurrent subscription race (two simultaneous POSTs), and plan change after cancellation (covered — 409)."*

**Rating**: ★★★★☆ (4/5)  
**Bug caught**: Re-subscribe after cancel tested before code was shipped

---

### Persona 6: Keiko — Non-technical Product Manager

*"I can follow the subscription lifecycle: subscribe, upgrade, downgrade, cancel, re-subscribe. The price in cents is clear (just divide by 100). My question: what happens at the billing cycle end? Can a user cancel 'at period end' rather than immediately? And can I offer a free trial period? Those are real business requirements for a SaaS product."*

**Rating**: ★★★☆☆ (3/5)  
**Feature requests**: Cancel at period end, free trial — expected gaps at FT scale

---

### DX Summary

| Persona | Rating | Primary concern |
|---------|--------|-----------------|
| Junko (Junior PHP) | ★★★★☆ | UNIQUE constraint → UPDATE for re-subscribe |
| Tariq (Mid backend) | ★★★★☆ | No plan change history |
| Priya (Tech Lead) | ★★★☆☆ | No pricing history, no audit trail |
| Markus (DevOps) | ★★★★☆ | No trial period or billing cycle logic |
| Amara (QA) | ★★★★☆ | Bug caught early, good isolation test |
| Keiko (PM) | ★★★☆☆ | No cancel-at-period-end or free trial |

**Overall DX**: ★★★★☆ (3.7/5) — The subscription lifecycle is well-modelled for a basic system. The one-row-per-user pattern is clean. Production gaps are expected (billing cycles, history, trials).

---

## Issues Raised for NENE2

None. Framework behaviour was as expected.

---

## Howto

`docs/howto/subscription-plan-management.md`
