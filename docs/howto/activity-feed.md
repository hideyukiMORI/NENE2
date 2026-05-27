# How-to: Activity Feed / Timeline API

> **FT reference**: FT277 (`NENE2-FT/feedlog`) — Activity feed: type-allowlisted events (9 types), JSON payload per event, user-scoped feed with IDOR → 404, pagination clamping (max 100), admin fail-closed, 24 tests / 37 assertions PASS.
>
> Also validated in FT219 (`NENE2-FT/feedlog` precursor) — VULN assessment on same pattern.

This guide shows how to build an activity feed system with typed events, user scoping, and pagination using NENE2.

## Features

- Post typed activity events (strictly allowlisted types)
- JSON payload storage (arbitrary metadata per event type)
- User-scoped feed with IDOR protection (returns 404 for unauthorized access)
- Event type filtering via query parameter
- Timestamp-descending pagination (newest-first)
- Admin can post events on behalf of users

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/events` | User | Post an activity event |
| `GET` | `/users/{userId}/feed` | User (self or admin) | Get feed with optional type filter |

## Event Type Allowlist (VULN-B)

Strictly allowlisting event types prevents mass assignment and arbitrary event injection:

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## Payload Storage

Payloads are stored as JSON strings and decoded on retrieval:

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## IDOR Protection (VULN-C)

Feed access returns 404 (not 403) when an unauthorized user tries to view another user's feed:

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Pagination with Type Filtering

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

Unknown types in the `?type=` parameter are silently ignored (null = no filter applied).

## VULN Assessment Results (FT219)

- **VULN-B**: `in_array(..., strict: true)` prevents any unlisted event type
- **VULN-C**: IDOR returns 404 to hide feed existence from unauthorized callers
- **VULN-D**: Admin fail-closed — empty admin key always returns false
- **VULN-F**: `is_array($payload)` ensures payload is always a JSON object, not a scalar
- **VULN-G**: `ctype_digit()` guards `userId` path parameter
- **VULN-I**: `clampInt()` bounds `limit` (1–100) and `offset` (0–MAX_INT)

## Security Patterns

- **`ctype_digit()`**: ReDoS-safe integer validation for path params
- **`is_array()`**: Payload must be a JSON object (array in PHP) — not string, number, null
- **Parameterized queries**: All SQL uses `:named` parameters — no string concatenation
- **`in_array(..., true)`**: Strict comparison prevents type-coercion bypass

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept free-form event type string | Uncontrolled types pollute the feed; hard to build type-specific queries |
| Store payload as TEXT without JSON validation | `is_array($payload)` ensures a JSON object; scalars/arrays break downstream consumers |
| Trust raw `limit` from query string | No upper bound → full table scan on large datasets |
| Use `in_array($type, TYPES)` without `true` | Loose comparison; `0 == 'post_created'` in some PHP versions |
| Return 403 on feed access for wrong user | Reveals the user exists; use 404 to hide user enumeration |
| Index only on `user_id` | Missing `id DESC` in composite index causes slow ORDER BY on large feeds |
