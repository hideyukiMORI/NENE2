# How-to: Feature Flags API

> **FT reference**: FT270 (`NENE2-FT/featureflaglog`) — Feature flag API: priority-chain evaluation (user target → tenant target → globally_enabled → rollout_pct hash), crc32-based deterministic bucket assignment, user/tenant kill switches, flag UNIQUE name constraint, 21 tests / 31 assertions PASS.

Feature flags let you toggle functionality at runtime without deploying code. The core decisions are: where to store state (DB vs config), how to evaluate priority when multiple rules apply, and how to handle rollout percentages without per-user tracking.

---

## Routes

| Method   | Path                                  | Description                              |
|----------|---------------------------------------|------------------------------------------|
| `POST`   | `/flags`                              | Create a new feature flag                |
| `GET`    | `/flags/{name}`                       | Get flag details with targets            |
| `POST`   | `/flags/{name}/toggle`                | Set globally_enabled on/off              |
| `PUT`    | `/flags/{name}/rollout`               | Set rollout percentage (0–100)           |
| `PUT`    | `/flags/{name}/targets`               | Upsert a user or tenant target override  |
| `DELETE` | `/flags/{name}/targets/{type}/{id}`   | Remove a specific target override        |
| `POST`   | `/flags/{name}/evaluate`              | Evaluate the flag for a user/tenant      |

---

## Core components

- **Feature flag registry**: one row per flag with a name, global on/off switch, and rollout percentage.
- **Flag targets**: per-user or per-tenant overrides that win over the global state.
- **Evaluator**: applies the priority chain and returns a boolean for a given user.

## Schema

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## Evaluation priority

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. Explicit user-level target wins first
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. Tenant-level target
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. Global switch
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. Rollout percentage: deterministic bucket via crc32 hash
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. Default off
        return false;
    }
}
```

Priority order (highest wins):
1. User-level target (`target_type = 'user'`)
2. Tenant-level target (`target_type = 'tenant'`)
3. `globally_enabled = 1`
4. `rollout_pct > 0` with hash-based bucket
5. `false`

## Rollout percentage — deterministic bucket

`crc32($userId . '.' . $flagName) % 100` produces a stable bucket per (user, flag) pair. The same user always lands in the same bucket, so their experience is consistent across requests. Appending the flag name prevents all flags from rolling out to the same users at `pct = 10`.

Important: `crc32()` can return negative values on 64-bit systems — use `abs()`.

## Targets as overrides

A target with `enabled = false` is a kill switch: it disables the flag for that user or tenant even when `globally_enabled = 1`. This is the canonical way to exclude a specific user from a rollout.

```php
// User-level kill switch (overrides global enable)
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// Tenant early-access (overrides global disable)
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## Upsert pattern for targets

Targets use `INSERT OR REPLACE` / upsert semantics — calling the same endpoint twice with different `enabled` values updates the existing row rather than creating a duplicate:

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

The UNIQUE constraint on `(flag_id, target_type, target_id)` enforces that there is at most one override per (flag, target) pair.

## Conflict response for duplicate flag names

`feature_flags.name` has a UNIQUE constraint. On duplicate creation, the DB throws a `RuntimeException`. Catch it and return 409 Conflict rather than 500:

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // caller maps null → 409
}
```

## Design decisions

**Why DB-backed rather than config-file?**
Config files require a deploy to change a flag. DB-backed flags can be toggled live without touching code or restarting processes.

**Why deterministic hash for rollout rather than random?**
Random selection means the same user flips between enabled/disabled across requests. A stable hash gives each user a consistent experience for the lifetime of the flag.

**Why allow `enabled = false` targets?**
A flag system without kill switches is incomplete. `enabled = false` is the safest way to exclude a user from a rollout that is already globally enabled — no code change, no deploy.

**Why separate `globally_enabled` and `rollout_pct`?**
`globally_enabled = 1` is an explicit all-or-nothing switch. `rollout_pct` is for gradual exposure. Keeping them separate avoids overloading one field with two different meanings.

---

## Example responses

**POST /flags** (201 Created):
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}** (200 OK):
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate** (200 OK):
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Use random number for rollout per request | Same user flips between enabled/disabled across requests — inconsistent UX |
| Forget `abs()` on `crc32()` | crc32 can return negative values on 64-bit PHP — modulo gives wrong bucket |
| Allow arbitrary `target_type` values | Uncontrolled enum makes evaluation logic unbounded; restrict to `'user'` and `'tenant'` |
| No `UNIQUE (flag_id, target_type, target_id)` | Duplicate targets make evaluation ambiguous — first row wins arbitrarily |
| Use flag name as `target_id` | Flag name can change; use stable IDs for user/tenant targeting |
| Return 500 on duplicate flag name | The name uniqueness violation is a domain error, not a server error; map to 409 Conflict |
