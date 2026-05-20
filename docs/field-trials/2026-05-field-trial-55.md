# Field Trial 55 — Idempotency Keys for POST Requests (idempotencylog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/idempotencylog/`
**NENE2 version**: 1.5.19
**Theme**: `X-Idempotency-Key` header, DB-backed key → response cache, replay on retry, `X-Idempotent-Replayed` header, no-duplicate guarantee

## Overview

Built a payment creation API to validate the idempotency key pattern:
- `POST /payments` accepts an optional `X-Idempotency-Key` header
- On first call: creates the payment, stores `sha256(key)` → serialized response body + status code in `idempotency_keys` table
- On retry with the same key: replays the stored response without creating a new payment, adds `X-Idempotent-Replayed: true` header
- Without a key: standard non-idempotent behavior (creates new payment each time)

## Endpoints Implemented

- `POST /payments` — create payment with optional idempotency key
- `GET /payments` — list all payments

## Test Results

15 tests, 25 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — Unused variable left by copy-paste during development [LOW]

**Symptom**: `$psr17 = new Psr17Factory()` was left inside the replay branch but `$psr17` was never used (the replay goes directly through `$this->json->create()`).

**Fix**: Removed the unused variable and the import.

**NENE2 impact**: None — this is a development error, not a framework issue. PHPStan level 8 does not flag unused local variable assignments (only unused imports and properties), so the `no_unused_imports` CS-Fixer rule would have caught the import if I had left it with no corresponding usage. Caught during code review.

---

## Patterns Validated

### DB-backed idempotency key store

```sql
CREATE TABLE IF NOT EXISTS idempotency_keys (
    key_hash    TEXT    PRIMARY KEY,
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
```

`sha256()` of the raw key is stored as primary key. This avoids storing arbitrary client-controlled strings at unlimited length.

### Replay logic in the route handler

```php
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
```

The replay returns the exact same status code and body as the original call. The `X-Idempotent-Replayed: true` header lets clients distinguish a fresh response from a cached replay.

### Save after successful creation

```php
$responseBody = json_encode($payment->toArray(), JSON_THROW_ON_ERROR);
$statusCode   = 201;

if ($idempotencyKey !== '') {
    $keyHash = hash('sha256', $idempotencyKey);
    $this->repo->saveIdempotencyRecord($keyHash, $statusCode, $responseBody, $now);
}

return $this->json->create($payment->toArray(), $statusCode);
```

The idempotency record is saved only after the payment is successfully created. This avoids caching 422 validation errors as idempotent responses.

### No-duplicate guarantee verified in tests

```php
$this->req('POST', '/payments', ['amount_cents' => 999], $key); // creates 1 payment
$this->req('POST', '/payments', ['amount_cents' => 999], $key); // replays
$this->req('POST', '/payments', ['amount_cents' => 999], $key); // replays

$list = $this->json($this->req('GET', '/payments'));
$this->assertSame(1, $list['total']); // only 1 payment in DB
```

---

## NENE2 Changes Required

None. The idempotency key pattern is a pure application-level concern. `$request->getHeaderLine()` from PSR-7 provides clean header access without any framework additions needed.
