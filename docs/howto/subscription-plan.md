# How-to: Subscription / Plan Management API (VULN-A~L)

This guide demonstrates a subscription management API where users subscribe to plans, with duplicate prevention, cancellation, and IDOR protection.

## Pattern Overview

- Seed plans are inserted at schema time (`free`, `starter`, `pro`, `annual`).
- Users subscribe via `POST /subscriptions` with a `plan_id`.
- Each (user, plan) pair can have at most one active subscription.
- Cancellation changes status to `'cancelled'`; cancelled subscriptions cannot be cancelled again.

## Schema

```sql
CREATE TABLE IF NOT EXISTS plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    price_cents INTEGER NOT NULL,
    interval    TEXT    NOT NULL DEFAULT 'monthly'
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE (user_id, plan_id, status)
);
```

## VULN-A: SQL Injection

All queries use PDO prepared statements. Plan names and user IDs are never interpolated.

## VULN-C: IDOR

Non-admin users can only access their own subscriptions. Accessing another user's subscription returns 404 (not 403):

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Subscription not found.');
}
```

## VULN-D: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

Path IDs use `ctype_digit()` + length limit. Non-numeric paths (`/subscriptions/abc`) return 404 immediately.

## VULN-J: Type Confusion

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id must be a positive integer.');
}
```

String `"2"`, float `2.5`, and zero all return 422.

## Duplicate Prevention

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

Attempting to subscribe to an already-active plan returns 409.

## Cancel Idempotency

The `cancel()` method checks status before updating. A second cancel attempt on a `'cancelled'` subscription returns `'already_cancelled'` → 409 (not 204).

## JOIN for Rich Response

Subscription detail includes plan info via JOIN:

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## Routes

```
GET    /plans                           List available plans (public)
POST   /subscriptions                   Subscribe to a plan (X-User-Id required)
GET    /subscriptions/{id}              Get subscription (owner or admin)
POST   /subscriptions/{id}/cancel       Cancel subscription (owner or admin)
GET    /users/{userId}/subscriptions    List user's subscriptions (owner or admin)
```

## See Also

- FT213 source: `../NENE2-FT/subscriptionlog/`
- Related: `docs/howto/coupon-redemption.md` (FT204, also stateful per-user limits)
- Related: `docs/howto/wish-list-api.md` (FT207, VULN pattern)
