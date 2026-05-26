# How to Build a Multi-Device Session Manager

> **Pattern proven by FT186 sessionlog** — multi-device session tracking, IDOR prevention, mass-assignment guard, timing-oracle-free revocation.

---

## What This Covers

A multi-device session manager lets users:

1. **Create sessions** on login (each device gets its own token)
2. **List active sessions** scoped to their user ID
3. **Revoke a single session** (log out from one device)
4. **Revoke all except current** (log out from all other devices)

Security guarantees demonstrated:

| Concern | Technique |
|---|---|
| IDOR prevention | All mutations scope `WHERE token = ? AND user_id = ?` |
| Mass assignment | `token`, `user_id`, `created_at`, `revoked_at` set server-side only |
| Timing oracle | Generic 404 for all failures — no ownership leak |
| Integer overflow | `V::queryInt()` 18-digit strlen guard |
| Type confusion | `V::str()` rejects non-string `device_name`/`ip_address` |
| Token entropy | `bin2hex(random_bytes(32))` — 256-bit, 64 hex chars |
| SQL injection | PDO parameterized queries + `/^[0-9a-f]{64}$/` gate |

---

## Schema

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = active
);
```

`revoked_at IS NULL` is the active-session predicate. Soft delete avoids losing audit history.

---

## API Design

| Method | Path | Header | Description |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Create a session |
| `GET` | `/sessions` | `X-User-Id` | List own active sessions |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Revoke one session |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Revoke all except current |

---

## Core Pattern: 256-bit Token Generation

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // 256-bit entropy, 64 hex chars
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` produces 64 lowercase hex characters from a cryptographically secure source. Never accept tokens from user input.

---

## Core Pattern: IDOR Prevention

```php
// WRONG — lets any authenticated user revoke any session
UPDATE sessions SET revoked_at = ? WHERE token = ?

// CORRECT — must own the session
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

`rowCount() > 0` returns `false` when the token exists but belongs to another user — the handler responds with a generic 404 (see timing oracle section).

---

## Core Pattern: Mass Assignment Guard

```php
// POST /sessions handler — attacker body: {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId comes from X-User-Id header — never from body
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // Only safe, validated fields are passed down
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at set by repository — not from body
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## Core Pattern: Timing Oracle Prevention

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I: invalid format → 404 immediately (no DB query)
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // IDOR guard: revokeForUser returns false when:
    //   - token doesn't exist
    //   - token belongs to a different user
    //   - token is already revoked
    // All cases return the SAME 404 — no ownership oracle
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

Never distinguish "not found" from "wrong user" in the response. An attacker who knows a victim's token must not learn whether it's active or belongs to that user.

---

## Core Pattern: Revoke All Except Current

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

The caller passes `X-Current-Session` header. Both `user_id` and the exclusion condition are enforced in the single query.

---

## Core Pattern: Overflow-Safe Limit Validation

```php
// VULN-A: V::queryInt refuses >18 digits — prevents silent PHP int overflow
// VULN-F: ctype_digit is O(n) — no regex backtracking risk
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` rejects negative numbers, floats, hex strings (`0x10`), and numbers > 18 digits.

---

## Route Token Validation

Always validate the token format at the route layer before querying the DB:

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// In the handler:
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

This blocks SQL injection strings, path traversal attempts, and short/long tokens before any database interaction.

---

## Test Results (FT186)

```
54 tests / 116 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

VULN-A~L coverage:

| Vuln | Pattern | Test |
|---|---|---|
| A | limit 19-digit overflow | `testVulnALimitOverflow19Digits` |
| B | device_name type confusion | `testVulnBDeviceNameAsInteger` |
| C | SQL injection in token | `testVulnCSqlInjectionToken` |
| D | negative/float/hex limit | `testVulnDNegativeLimitRejected` |
| E | IDOR revocation | `testVulnECannotRevokeOtherUsersSession` |
| F | ReDoS-style long limit | `testVulnFVeryLongLimitRejected` |
| H | timing oracle | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | empty/short/traversal token | `testVulnIEmptyTokenSegmentNotMatched` |
| L | mass assignment | `testVulnLTokenFromBodyIsIgnored` |

Source: [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
