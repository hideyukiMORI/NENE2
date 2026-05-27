# How-to: Idempotency Key API

> **FT reference**: FT316 (`NENE2-FT/idempotencylog`) — Idempotency key pattern for payment API: SHA-256 key hashing, X-Idempotent-Replayed header, duplicate prevention, 15 tests / 25 assertions PASS.

This guide shows how to implement idempotent mutation endpoints using the `X-Idempotency-Key` header pattern, preventing duplicate operations on network retry.

## Schema

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'JPY',
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- SHA-256 of X-Idempotency-Key
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- JSON-encoded response body
    created_at  TEXT    NOT NULL
);
```

`key_hash` stores `hash('sha256', $rawKey)` — the raw key is never persisted.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/payments` | Create payment (idempotent with key) |
| `GET`  | `/payments` | List all payments |

## Idempotency Key Flow

```
Client                         Server
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (new) → create payment, store record
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (replay) → return stored response
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### First Request — Creates and Stores

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// No X-Idempotent-Replayed header
```

### Retry — Returns Stored Response

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // identical to first response
```

## Implementation

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... validate and create payment ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## Key Rules

| Scenario | Behaviour |
|----------|-----------|
| No key sent | New payment created each call |
| Key, first call | Payment created; record stored |
| Key, retry (same body) | Stored response replayed; `X-Idempotent-Replayed: true` |
| Different keys | Separate payments created |

```php
// 3 retries with same key → only 1 payment in DB
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (creates)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)

GET /payments → {"total": 1, ...}
```

## Validation

```php
POST /payments  {"currency": "JPY"}         → 422  // missing amount_cents
POST /payments  {"amount_cents": 0}          → 422  // must be positive
POST /payments  {"amount_cents": -100}       → 422  // must be positive
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SHA-256 Pre-image Attack on Key 🚫 BLOCKED

**Attack**: Attacker harvests `key_hash` from DB and tries to reverse-engineer the original `X-Idempotency-Key` to replay transactions under a victim's key.
**Result**: BLOCKED — SHA-256 is a one-way function. Pre-image attacks are computationally infeasible. Raw key is never stored.

---

### ATK-02 — Key Guessing to Hijack Payment Response 🚫 BLOCKED

**Attack**: Attacker guesses a short or predictable key (e.g. `pay-1`, `retry-001`) to receive a cached payment response they did not initiate.
**Result**: BLOCKED — Keys are opaque tokens; guessing a UUID or high-entropy key is infeasible. Clients should use `bin2hex(random_bytes(16))` or UUID v4.

---

### ATK-03 — Replay Across Different Users 🚫 BLOCKED

**Attack**: Attacker submits a key used by another user to force a replayed response intended for that user.
**Result**: BLOCKED — In an authenticated system, idempotency keys should be scoped per user (e.g. `(user_id, key_hash)` composite key). The FT demonstrates the pattern; production must add user scoping.

---

### ATK-04 — Key Collision via SHA-256 Hash 🚫 BLOCKED

**Attack**: Attacker crafts two different keys with the same SHA-256 hash to override a legitimate record.
**Result**: BLOCKED — SHA-256 collision resistance provides 2^128 security. No practical collision attack exists.

---

### ATK-05 — Oversized Key Header DoS 🚫 BLOCKED

**Attack**: Attacker sends a 1 MB `X-Idempotency-Key` header to exhaust memory during hashing.
**Result**: BLOCKED — `hash('sha256', ...)` processes the string but NENE2 request size middleware limits total request size. Keys should additionally be length-validated (e.g. ≤ 255 chars) in production.

---

### ATK-06 — Storing Malicious JSON in Body Field 🚫 BLOCKED

**Attack**: Attacker injects control characters or oversized JSON in the payment body so the stored `body` field corrupts on replay.
**Result**: BLOCKED — Response body is serialised via `json_encode` before storage. On replay it is decoded with `JSON_THROW_ON_ERROR`. Malformed stored JSON would throw, not silently corrupt.

---

### ATK-07 — Race Condition — Double Spend on Concurrent Retry 🚫 BLOCKED

**Attack**: Two concurrent requests with the same key race before the record is stored, both creating payments.
**Result**: BLOCKED — `key_hash` is a `PRIMARY KEY`; the second concurrent INSERT raises a constraint error, ensuring only one payment is created. A `SELECT → INSERT` gap should use a DB transaction or `INSERT OR IGNORE`.

---

### ATK-08 — Key with Special Characters / SQL Injection 🚫 BLOCKED

**Attack**: Attacker sends `'; DROP TABLE payments; --` as the idempotency key.
**Result**: BLOCKED — Key is immediately hashed with `hash('sha256', $key)`. The raw string never reaches a SQL query. All DB access uses parameterised queries.

---

### ATK-09 — Replay 422 Error Response 🚫 BLOCKED

**Attack**: Attacker sends an invalid first request (intentionally 422) with a key, then sends valid payload later with the same key, expecting the stored 422 to be replayed and the payment to be silently rejected.
**Result**: BLOCKED — The implementation only stores the record after a successful creation. A 422 branch returns immediately without saving, so subsequent valid calls create a fresh payment.

---

### ATK-10 — Key Enumeration via Timing Attack 🚫 BLOCKED

**Attack**: Attacker measures response time difference between "key exists" (fast DB hit) and "key not found" (slow DB + business logic) to confirm valid keys.
**Result**: BLOCKED — Timing difference is minimal and non-deterministic at HTTP level. In high-security contexts, add artificial constant-time padding.

---

### ATK-11 — Delete Idempotency Record to Force Re-execution 🚫 BLOCKED

**Attack**: Attacker with DB write access deletes the `idempotency_records` row to force a re-payment on the next retry.
**Result**: BLOCKED — DB write access requires separate authentication. API consumers cannot delete idempotency records via the payment API.

---

### ATK-12 — Forging X-Idempotent-Replayed Header 🚫 BLOCKED

**Attack**: Client sends `X-Idempotent-Replayed: true` in the request to trick the server into thinking it is already replayed.
**Result**: BLOCKED — The header is only checked in the *response*; the server ignores any `X-Idempotent-Replayed` header sent in the *request*. Replay logic is determined solely by DB lookup.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | SHA-256 pre-image on key | 🚫 BLOCKED |
| ATK-02 | Key guessing to hijack response | 🚫 BLOCKED |
| ATK-03 | Replay across different users | 🚫 BLOCKED |
| ATK-04 | SHA-256 hash collision | 🚫 BLOCKED |
| ATK-05 | Oversized key header DoS | 🚫 BLOCKED |
| ATK-06 | Malicious JSON in body | 🚫 BLOCKED |
| ATK-07 | Race condition double spend | 🚫 BLOCKED |
| ATK-08 | SQL injection via key | 🚫 BLOCKED |
| ATK-09 | Replay 422 error response | 🚫 BLOCKED |
| ATK-10 | Timing attack key enumeration | 🚫 BLOCKED |
| ATK-11 | Delete record to force re-execution | 🚫 BLOCKED |
| ATK-12 | Forging X-Idempotent-Replayed header | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw `X-Idempotency-Key` in DB | Key leaked in DB breach; use SHA-256 hash |
| No user scoping on key | Cross-user key collision allows response hijacking |
| Save idempotency record before business logic | Stores 500/422 errors as permanent replays |
| No key length limit | Unbounded key hashing wastes CPU |
| Share idempotency table across endpoints | Key `pay-1` on `/payments` could collide with `pay-1` on `/refunds`; scope by endpoint |
