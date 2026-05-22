# How to Add Request Deduplication

Prevent duplicate processing from network retries or double-clicks using an `Idempotency-Key` header. The server caches responses per key and replays them on subsequent identical requests.

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

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/payments` | Process payment (idempotency-key required) |
| `POST` | `/orders` | Create order (idempotency-key required) |

## Handler Pattern

Every mutating endpoint that should be idempotent follows the same three-step pattern:

```php
// 1. Require the Idempotency-Key header
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. Return cached response if key already used
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. Process and cache
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

The `replayed: true` field signals to clients that the response was served from cache.

## Strict Amount Validation

Reject non-integer inputs at the boundary — PHP's `(int)` cast silently truncates strings like `"100; DROP TABLE …"` to `100`. Use an explicit type check:

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL and Expiry

Keys expire after 24 hours (86400 seconds). Expired entries are treated as fresh — the same key can be reused after expiry:

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## Security Properties

- **SQL injection via key header**: parameterized queries store malicious keys as literals.
- **Replay flood**: 10 identical requests create exactly 1 record in the business table.
- **Whitespace-only key**: `trim()` before empty check prevents `"   "` as a valid key.
- **Type injection in numeric fields**: `ctype_digit()` check rejects partial-integer strings.
- **No internal leaks**: 400/422 responses contain only the `error` or `errors` fields — no paths, stack traces, or engine details.
