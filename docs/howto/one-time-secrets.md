# How-To: One-Time Secret API & ATK-01~12 Cracker Attack Test

> **NENE2 Field Trial 184** — Cracker Attack Test Cycle (ATK-01~12).
> Token IS the credential. Atomic consumption prevents race conditions.

---

## What This Trial Proves

A one-time secret stores an encrypted message that can only be read once.
After the first successful read, the secret is permanently consumed.

Security requirements:
1. **256-bit token entropy** — brute force is computationally infeasible
2. **Atomic consumption** — `UPDATE WHERE consumed=0` prevents double-read race conditions
3. **IDOR prevention** — delete requires both token + user ownership
4. **Mass assignment blocked** — consumed/token/created_at are server-side only
5. **Type safety** — V::str() / V::userId() / V::queryInt() reject non-string inputs

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/secrets` | X-User-Id | Create a one-time secret |
| `GET` | `/secrets` | X-User-Id | List own secrets (metadata only, no message) |
| `GET` | `/secrets/{token}` | — | Read + consume (token IS the credential) |
| `DELETE` | `/secrets/{token}` | X-User-Id | Cancel before reading (must own) |

---

## ATK-01~12 Results

| ID | Attack Vector | Defense | Result |
|---|---|---|---|
| ATK-01 | SQL injection in token | PDO parameterized queries | ✅ PASS |
| ATK-02 | IDOR cross-user delete | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | Mass assignment (`consumed=1` in body) | Server-side fields only | ✅ PASS |
| ATK-04 | XSS payload in message | JSON API — no HTML rendering | ✅ PASS |
| ATK-05 | Double-encoded / malformed token | `/^[0-9a-f]{64}$/` format check | ✅ PASS |
| ATK-06 | Auth bypass on read | Token IS the credential — by design | ✅ PASS |
| ATK-07 | Message/password as non-string | `V::str()` enforces `is_string()` | ✅ PASS |
| ATK-08 | 20-digit overflow in limit/offset | `V::queryInt()` strlen > 18 guard | ✅ PASS |
| ATK-09 | ReDoS in limit parameter | `ctype_digit()` — O(n), no backtracking | ✅ PASS |
| ATK-10 | Brute force token | `random_bytes(32)` = 2^256 entropy | ✅ PASS |
| ATK-11 | Race condition double-read | `UPDATE WHERE consumed=0` + rowCount check | ✅ PASS |
| ATK-12 | Header injection in X-User-Id | `V::userId()` enforces `ctype_digit()` | ✅ PASS |

**12/12: PASS**

---

## Core Pattern: Atomic Consumption

The critical security invariant — a secret can only be read once:

```php
// SecretRepository::consumeByToken()

// Step 1: Fetch secret (ordinary SELECT — not the guard)
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// Step 2: Check consumed flag (early exit for common case)
if ($secret['consumed']) return null;

// Step 3: Atomic UPDATE — this is the real guard
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// Step 4: rowCount() === 0 means another reader won the race
if ($update->rowCount() === 0) {
    return null; // someone else consumed it between our SELECT and this UPDATE
}

// Step 5: We won — return the secret
return Secret::fromRow($secret);
```

**Why this works:** SQLite and most RDBMS guarantee that `UPDATE WHERE consumed=0` is atomic.
Only one concurrent writer can change `consumed` from 0→1. The loser's `rowCount()` returns 0.

---

## Token Generation

```php
$token = bin2hex(random_bytes(32)); // 64 hex chars = 32 bytes = 256 bits
```

- `random_bytes()` uses the OS CSPRNG (equivalent to `/dev/urandom`)
- 2^256 tokens at 10^12 guesses/second ≈ 10^60 years to brute force
- Tokens are unique in the DB (`UNIQUE` constraint)

---

## Token Format Validation

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Rejects: uppercase hex, path traversal ../../, URL-encoded, integers, empty
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## IDOR Prevention (ATK-02)

```php
// DELETE requires BOTH token ownership AND user_id match
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// Returns 404 regardless of reason — avoids enumeration oracle
return $stmt->rowCount() > 0;
```

---

## Mass Assignment Prevention (ATK-03)

Server-side fields are **never read from the request body**:

```php
// POST /secrets handler — only message, password, expires_at are accepted from body
$token        = bin2hex(random_bytes(32));  // server-generated
$consumed     = 0;                          // always starts unconsumed
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // server time
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // hashed server-side

// body['consumed'], body['token'], body['user_id'], body['created_at'] are silently ignored
```

---

## V.php Validation Chain

```php
// ATK-07: message must be a string (rejects int, bool, null, array)
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: X-User-Id must be ctype_digit + positive + max 18 chars
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: limit must be numeric, max 18 digits, in range 1–100
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## Optional Password Protection

```php
// Storage: SHA-256 hash only (not plaintext)
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// Verification: constant-time comparison (timing-safe)
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // wrong password → silent 404 (no oracle)
}
```

> **Note:** Wrong password returns 404 (not 403) to prevent oracle attacks.
> The secret is NOT consumed on wrong password — only the correct password consumes it.

---

## Metadata List (No Message Leakage)

```php
// GET /secrets — returns metadata only, never the message
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' is intentionally omitted
    ];
}
```

---

## Test Results

```
85 tests / 209 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Atomic consumption | `UPDATE WHERE consumed=0` + `rowCount()` check — not SELECT then UPDATE |
| Token entropy | `random_bytes(32)` minimum (256 bits) — never sequential IDs |
| Token format | Allowlist regex anchored at both ends (`/^[0-9a-f]{64}$/`) |
| IDOR | All write operations scope by `token AND user_id` |
| Mass assignment | Token, consumed, created_at — server-side only, never from body |
| Password timing | `hash_equals()` for constant-time comparison |
| Wrong password | 404 not 403 — avoids confirming the secret exists |
| Metadata list | Omit message from list endpoint — read only on consume |

Full example: [`../NENE2-FT/onetimelog/`](https://github.com/hideyukiMORI/NENE2-examples) in the examples repository.
