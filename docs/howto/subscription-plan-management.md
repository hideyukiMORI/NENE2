# How to Build Subscription Plan Management with NENE2

This guide walks through building a subscription system — users subscribe to plans, upgrade/downgrade, cancel, and re-subscribe. Plans are seeded in the database at schema time.

**Field Trial**: FT137  
**NENE2 version**: ^1.5  
**Covered topics**: plan seeding, subscription lifecycle, re-subscribe after cancel, idempotent cancel, ownership enforcement

---

## What we're building

- `GET /plans` — list all available plans (public)
- `POST /users/{id}/subscription` — subscribe to a plan (or re-subscribe after cancel)
- `GET /users/{id}/subscription` — view current subscription (owner only)
- `PUT /users/{id}/subscription` — change plan (owner only, active subscription required)
- `DELETE /users/{id}/subscription` — cancel subscription (owner only)

---

## Database schema

```sql
CREATE TABLE plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    slug        TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- one subscription per user
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    CHECK (status IN ('active', 'cancelled'))
);

-- Seed plans
INSERT INTO plans (slug, name, price_cents) VALUES ('free', 'Free', 0);
INSERT INTO plans (slug, name, price_cents) VALUES ('pro', 'Pro', 999);
INSERT INTO plans (slug, name, price_cents) VALUES ('enterprise', 'Enterprise', 4999);
```

`UNIQUE (user_id)` on subscriptions means only one row per user — re-subscription UPDATEs the existing row rather than inserting a new one.

---

## Subscription lifecycle

```
No subscription
    ↓ POST /subscription (plan=X)
Active (plan=X)
    ↓ PUT /subscription (plan=Y)     ↓ DELETE /subscription
Active (plan=Y)                   Cancelled
    ↑ POST /subscription (plan=Z) ←── (re-subscribe reactivates the row)
```

---

## Re-subscribe after cancel

When a user cancels and then subscribes again, there's already a row in `subscriptions` for them. INSERT would fail the UNIQUE constraint. Instead, UPDATE the existing row:

```php
public function subscribe(int $userId, int $planId, string $now): void
{
    $existing = $this->findSubscription($userId);

    if ($existing !== null) {
        // Reactivate a cancelled subscription
        $this->executor->execute(
            "UPDATE subscriptions SET plan_id = ?, status = 'active', started_at = ?, cancelled_at = NULL WHERE user_id = ?",
            [$planId, $now, $userId],
        );

        return;
    }

    $this->executor->execute(
        'INSERT INTO subscriptions (user_id, plan_id, status, started_at) VALUES (?, ?, ?, ?)',
        [$userId, $planId, 'active', $now],
    );
}
```

The handler only returns 409 for *active* subscriptions, not cancelled ones:

```php
if ($existing !== null && $existing['status'] === 'active') {
    return $this->responseFactory->create(['error' => 'already subscribed, use PUT to change plan'], 409);
}
```

---

## Cancel — idempotent via `WHERE status = 'active'`

```php
public function cancel(int $userId, string $now): bool
{
    $count = $this->executor->execute(
        "UPDATE subscriptions SET status = 'cancelled', cancelled_at = ?
         WHERE user_id = ? AND status = 'active'",
        [$now, $userId],
    );

    return $count > 0;
}
```

If the subscription is already cancelled, the UPDATE affects 0 rows → handler returns 404. This prevents double-cancel from silently succeeding.

---

## Change plan — PUT requires active subscription

```php
$sub = $this->repo->findSubscription($userId);

if ($sub === null) {
    return $this->responseFactory->create(['error' => 'no subscription, use POST to subscribe'], 404);
}

if ($sub['status'] === 'cancelled') {
    return $this->responseFactory->create(['error' => 'subscription is cancelled, use POST to re-subscribe'], 409);
}
```

PUT is for changing an *active* plan. If the subscription is cancelled, the user must re-subscribe with POST first.

---

## Plan data in the response

The subscription response includes denormalized plan data (via JOIN) so clients don't need a second request:

```json
{
  "id": 1,
  "user_id": 1,
  "plan_slug": "pro",
  "plan_name": "Pro",
  "price_cents": 999,
  "status": "active",
  "started_at": "2026-05-21 12:00:00",
  "cancelled_at": null
}
```

---

## AppFactory

```php
final class AppFactory
{
    public static function createSqliteApp(string $dbPath): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '', port: 1, name: $dbPath, user: '', password: '', charset: '',
        );

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);

        $repo      = new PlanRepository($executor);
        $registrar = new RouteRegistrar($repo, $json);

        return new RuntimeApplicationFactory($psr17, $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
    }
}
```

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| 409 for re-subscribe after cancel | Only 409 for `status = 'active'`; UPDATE the row for cancelled |
| INSERT for re-subscribe | UNIQUE constraint on user_id prevents it — use UPDATE instead |
| PUT on cancelled subscription silently works | Check `$sub['status'] === 'cancelled'` → 409 before UPDATE |
| Returning 404 on double-cancel | Already cancelled → UPDATE affects 0 rows → return 404 explicitly |
| Forgetting JOIN for plan data in response | Use `INNER JOIN plans p ON p.id = s.plan_id` in findSubscription |
