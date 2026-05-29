---
title: "How-to: Rating & Review API"
category: product
tags: [rating, review, upsert, aggregate, distribution]
difficulty: intermediate
related: [product-review-system, upvote-downvote-api, voting-system]
---

# How-to: Rating & Review API

> **FT reference**: FT333 (`NENE2-FT/ratinglog`) — Per-item, per-user rating system with score validation (1–5), upsert semantics, summary with distribution breakdown, and vulnerability assessment, 16 tests / 40+ assertions PASS.

This guide shows how to build a rating system where users submit numeric scores with optional text reviews, and the API computes live aggregate summaries.

## Schema

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` enforces one rating per rater per item. `item_id` and `rater_id` are opaque string identifiers — no foreign key constraint required.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `PUT`  | `/items/{itemId}/ratings/{raterId}` | Create or update rating (upsert) |
| `GET`  | `/items/{itemId}/ratings` | List all ratings for item |
| `GET`  | `/items/{itemId}/ratings/summary` | Aggregate summary with distribution |
| `GET`  | `/items/{itemId}/ratings/{raterId}` | Get one rater's rating |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | Delete a rating |

## Create / Update Rating (Upsert)

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// Update existing rating
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Changed my mind."}
→ 200  {"score": 3}
```

`PUT` with `UNIQUE(item_id, rater_id)` acts as a natural upsert (`INSERT OR REPLACE`). The same endpoint handles both create and update without a separate `PATCH`.

### Validation

```php
// Missing score
PUT /items/product-1/ratings/alice  {"review": "Nice"}
→ 422

// Out of range
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

Score must be an integer in [1, 5]. `review` is optional (defaults to `""`).

## List Ratings

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

Ratings are scoped to the item — `product-2`'s ratings never appear in `product-1`'s list.

## Summary with Distribution

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// No ratings yet
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` always returns all five keys even when counts are zero — clients can render star bars without null checks.

## Get Individual Rating

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## Delete Rating

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

After deletion, the summary re-computes immediately on the next request.

```php
// Before: alice(5) + bob(1), average=3.0
DELETE /items/product-1/ratings/bob

// After: alice(5) only
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## Vulnerability Assessment

### V-01 — Rating Impersonation (IDOR on raterId) ⚠️ EXPOSED

**Risk**: Any client can submit or delete a rating using any `raterId` path segment.
**Finding**: EXPOSED — `raterId` in the URL is not validated against an authenticated actor. An attacker can POST a 1-star review as `raterId: "competitor"` or delete another user's review. Mitigation: authenticate the rater (session, JWT, or `X-User-Id` header) and reject requests where the authenticated identity does not match the path `raterId`.

---

### V-02 — Score Range Bypass 🛡️ SAFE

**Risk**: Attacker submits `score: 0` or `score: 6` to produce invalid data or skew averages.
**Finding**: SAFE — Score is validated to `[1, 5]` before any DB write. Out-of-range values return 422. The DB-level `CHECK (score BETWEEN 1 AND 5)` provides a secondary guard.

---

### V-03 — Average Poisoning via Bulk Fake Ratings ⚠️ EXPOSED

**Risk**: Attacker registers thousands of user IDs and submits 1-star ratings to sink a product's average.
**Finding**: EXPOSED — No rate limiting or account verification is enforced at the rating endpoint. Mitigation: require account age / email verification before rating; apply per-IP and per-user rate limits; detect statistical anomalies (sudden burst of low scores).

---

### V-04 — XSS via Review Text ✅ SAFE

**Risk**: Attacker stores `<script>alert(1)</script>` in `review` to execute JavaScript on clients that render the review HTML.
**Finding**: SAFE — The API returns `application/json`. JSON encoding escapes HTML special characters (`<`, `>`, `&`). As long as clients parse and render the JSON value as text (not `innerHTML`), stored XSS is prevented. Server-side HTML-encoding as an additional layer is recommended.

---

### V-05 — SQL Injection via itemId / raterId 🛡️ SAFE

**Risk**: Attacker sends `item_id = "x' OR '1'='1"` or `rater_id = "'; DROP TABLE ratings--"` to manipulate the query.
**Finding**: SAFE — All queries use parameterized statements (`?` placeholders). Path segments are passed as bind values, never interpolated into SQL strings.

---

### V-06 — Unbounded Review Text (Storage Abuse) ⚠️ EXPOSED

**Risk**: Attacker submits a 100 MB review string to exhaust database/memory resources.
**Finding**: EXPOSED — No `max_length` check is enforced on `review`. Mitigation: add a `MAX_REVIEW_LENGTH` constant (e.g. 2000 characters) and return 422 if exceeded. Request size middleware provides a secondary guard.

---

### V-07 — Summary Average Integer Truncation 🛡️ SAFE

**Risk**: Averaging 3 ratings (5+3+4=12, 12/3=4.0) could lose precision on some DB engines.
**Finding**: SAFE — `AVG()` in SQLite returns a float. PHP casts the result to `float` before encoding. `(int)(5+3)/2` style truncation is not used.

---

### V-08 — Distribution Missing Keys (Client Crash) 🛡️ SAFE

**Risk**: If `distribution` omits keys for scores with zero ratings, clients that access `distribution[1]` crash with `undefined`.
**Finding**: SAFE — The API always returns all five keys (`1`–`5`) initialized to `0`. Clients do not need defensive null-checks.

---

### V-09 — Cross-Item Data Leak 🛡️ SAFE

**Risk**: `GET /items/product-1/ratings` returns ratings from `product-2`.
**Finding**: SAFE — All queries include `WHERE item_id = ?`. The isolation test explicitly verifies that rating `product-2` does not appear in `product-1`'s list.

---

### V-10 — Float Score to Bypass Integer Validation 🛡️ SAFE

**Risk**: Attacker sends `score: 4.9` (rounds to 5) or `score: 5.1` (rounds to 5 or 6) to bypass range check.
**Finding**: SAFE — Score is validated as a strict integer. A JSON float fails type validation and returns 422 before any range check.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Rating impersonation (IDOR on raterId) | ⚠️ EXPOSED |
| V-02 | Score range bypass | 🛡️ SAFE |
| V-03 | Average poisoning via bulk fake ratings | ⚠️ EXPOSED |
| V-04 | XSS via review text | ✅ SAFE |
| V-05 | SQL injection via itemId / raterId | 🛡️ SAFE |
| V-06 | Unbounded review text (storage abuse) | ⚠️ EXPOSED |
| V-07 | Summary average integer truncation | 🛡️ SAFE |
| V-08 | Distribution missing keys | 🛡️ SAFE |
| V-09 | Cross-item data leak | 🛡️ SAFE |
| V-10 | Float score to bypass integer validation | 🛡️ SAFE |

**7 SAFE, 3 EXPOSED** — Critical: authenticate `raterId`; add `review` length cap; apply rate limiting against bulk fake ratings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Trust `raterId` from path without authentication | Any client can rate or delete as any user |
| No `max_length` on review text | Storage bomb — single request writes gigabytes to the DB |
| Return `null` for distribution keys with zero count | Client code that accesses `distribution[2]` crashes |
| Recalculate average in PHP with `array_sum` | Lossy float arithmetic on large datasets; let the DB do `AVG()` |
| No per-user rate limit | Bulk fake accounts poison product averages |
| Use `SELECT * FROM ratings` without `WHERE item_id` | Cross-item data leak |
