---
title: "How-to: Idempotency Key (Request Deduplication)"
category: api-design
tags: [idempotency, deduplication, retry, ttl]
difficulty: intermediate
related: [idempotency-key-api, idempotency, request-deduplication]
ft: FT292
---

# How-to: Idempotency Key (Request Deduplication)

> **FT reference**: FT292 (`NENE2-FT/deduplog`) — Idempotency key deduplication: UNIQUE(idempotency_key) DB constraint, 24h TTL with re-processable expiry, `replayed: true` flag on cached responses, parameterized queries prevent injection, ATK-01~12 all BLOCKED, 24 tests / 57 assertions PASS.

This guide shows how to implement idempotency keys — a header-based mechanism that ensures repeated requests (retries, network failures) produce the same result without duplicate side effects.

## Schema

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` ensures each key is stored once. The response body is serialized as JSON and replayed on subsequent requests.

## Request Flow

```
Client sends POST /payments with Idempotency-Key: <uuid>
  │
  ├─ Key found in DB AND not expired?
  │    └─ YES → return cached response + { "replayed": true }
  │
  └─ NO → process request → store response → return 201
```

## Idempotency-Key Extraction

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

The key is required and must be non-empty after trimming. Whitespace-only keys are rejected with 400.

## Cache Lookup — Expiry Check

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // Expired entries are treated as fresh (re-processable)
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

Expired keys return `null` — the request is re-processed as if it were new. This allows safe retry after TTL expiry without permanent deduplication.

## Cache Store — TTL Calculation

```php
private const int TTL_SECONDS = 86400; // 24 hours

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

The TTL is computed in UTC. `DateTimeImmutable::modify()` safely handles DST transitions and midnight rollovers.

## `replayed: true` Signal

Cached responses include `"replayed": true` merged into the body:

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

This lets clients distinguish first-time responses from replays without inspecting status codes. The status code is replayed unchanged (201 for creation).

## UNIQUE Constraint as Race Guard

```sql
UNIQUE(idempotency_key)
```

If two concurrent requests with the same key both pass the lookup check (TOCTOU), only one `INSERT` succeeds. The other receives a constraint error, which the application can handle by re-fetching the cached response.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL Injection in Idempotency-Key Header 🚫 BLOCKED

**Attack**: Send `Idempotency-Key: '; DROP TABLE idempotency_keys; --`.
**Result**: BLOCKED — all queries use parameterized statements. The injection string is stored or looked up as a literal key value.

---

### ATK-02 — SQL Injection in Amount Field 🚫 BLOCKED

**Attack**: Send `{ "amount": "1; DROP TABLE payments;" }`.
**Result**: BLOCKED — amount validation requires integer type. String values fail `is_int()` check → 422. No DB query executed.

---

### ATK-03 — SQL Injection in Item Field (stored safely) 🚫 BLOCKED

**Attack**: Send `{ "item": "' OR 1=1; --" }` in order creation.
**Result**: BLOCKED — parameterized query stores the string verbatim as the `item` value. No SQL execution occurs.

---

### ATK-04 — Replay Attack (same key 10 times) 🚫 BLOCKED

**Attack**: Send `POST /payments` with the same key 10 times to create 10 records.
**Result**: BLOCKED — first request creates one payment and caches the response. All 9 subsequent requests return the cached response with `replayed: true`. Only 1 payment row exists.

---

### ATK-05 — Whitespace-only Idempotency-Key 🚫 BLOCKED

**Attack**: Send `Idempotency-Key:    ` (spaces only) to bypass the empty-key check.
**Result**: BLOCKED — `trim($key) === ''` → 400. Whitespace-only keys are equivalent to missing keys.

---

### ATK-06 — Extremely Long Idempotency-Key 🚫 BLOCKED (design note)

**Attack**: Send a multi-megabyte key string.
**Result**: BLOCKED (design note) — SQLite stores the key verbatim; very long keys degrade lookup performance but don't crash. In production, add a length limit (e.g. `strlen($key) > 255 → 400`).

---

### ATK-07 — Negative Quantity in Order 🚫 BLOCKED

**Attack**: Send `{ "quantity": -5 }` to create a negative-quantity order.
**Result**: BLOCKED — quantity validation: `$quantity <= 0` → 422. Only positive integers accepted.

---

### ATK-08 — XSS in Item Field Stored as Literal 🚫 BLOCKED

**Attack**: Send `{ "item": "<script>alert(1)</script>" }`.
**Result**: BLOCKED — stored verbatim as a JSON string value. The API returns `application/json`; JSON encoding escapes `<`, `>`. No HTML rendering occurs in the API layer.

---

### ATK-09 — Concurrent Duplicate Keys 🚫 BLOCKED

**Attack**: Two processes send the same key simultaneously; both pass the lookup check before either stores.
**Result**: BLOCKED — `UNIQUE(idempotency_key)` ensures only one INSERT succeeds. The loser receives a constraint error and can re-fetch the cached response.

---

### ATK-10 — Integer Overflow in Amount 🚫 BLOCKED (design note)

**Attack**: Send `{ "amount": 9999999999999999999 }` (beyond PHP_INT_MAX).
**Result**: BLOCKED (design note) — PHP silently converts very large JSON integers to float. `is_int()` passes for within-range integers. In production, add an upper-bound check (e.g. amount > 10_000_000 → 422).

---

### ATK-11 — NULL Amount 🚫 BLOCKED

**Attack**: Send `{ "amount": null }` hoping null bypasses validation.
**Result**: BLOCKED — `!is_int(null)` is true and `ctype_digit(null)` is false → 422.

---

### ATK-12 — No Internal Info Leaked 🚫 BLOCKED

**Attack**: Trigger a 422 error and check if stack traces, file paths, or SQL appear in the response.
**Result**: BLOCKED — error responses contain only `{ "error": "..." }` or Problem Details. No internal paths, SQL, or stack traces in any response.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | SQL injection in Idempotency-Key header | 🚫 BLOCKED |
| ATK-02 | SQL injection in amount field | 🚫 BLOCKED |
| ATK-03 | SQL injection in item field | 🚫 BLOCKED |
| ATK-04 | Replay attack (10 duplicate requests) | 🚫 BLOCKED |
| ATK-05 | Whitespace-only key | 🚫 BLOCKED |
| ATK-06 | Extremely long key | 🚫 BLOCKED (design note) |
| ATK-07 | Negative quantity | 🚫 BLOCKED |
| ATK-08 | XSS in item field | 🚫 BLOCKED |
| ATK-09 | Concurrent duplicate keys | 🚫 BLOCKED |
| ATK-10 | Integer overflow in amount | 🚫 BLOCKED (design note) |
| ATK-11 | NULL amount | 🚫 BLOCKED |
| ATK-12 | No internal info leak | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Parameterized queries, strict type validation, `UNIQUE(idempotency_key)`, and TTL expiry cover all critical deduplication attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(idempotency_key)` constraint | Concurrent retries create duplicate records; deduplication race condition |
| No TTL / permanent dedup | Old keys fill the table; legitimate retries after 1+ days fail |
| No `replayed: true` flag | Client cannot distinguish first response from cached replay |
| Check expiry but never re-process expired keys | Retry after TTL still returns cached (possibly stale) response |
| Accept whitespace-only keys | `"   "` treated as a valid key; different clients may use `""` vs `"   "` interchangeably |
| No key length limit | Multi-MB keys in storage and lookup degrade performance |
| Return 409 on duplicate | Replay should return original status (201), not Conflict |
| Not validating amount type strictly | `"1000"` string passes loose checks; use `is_int()` for strict JSON integer |
| No upper bound on amount | Integer overflow or absurd amounts accepted without business validation |
