# How to Add A/B Testing

Run controlled experiments by assigning users to variants and collecting conversion events.

## Schema

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/experiments` | Create experiment (starts in `draft`) |
| `GET` | `/experiments` | List all experiments |
| `GET` | `/experiments/{id}` | Get experiment + variants |
| `PUT` | `/experiments/{id}/status` | Transition status |
| `POST` | `/experiments/{id}/variants` | Add a variant |
| `POST` | `/experiments/{id}/assign` | Assign user to variant (idempotent) |
| `POST` | `/experiments/{id}/events` | Record a conversion event |
| `GET` | `/experiments/{id}/results` | Aggregated CVR per variant |

## Status Lifecycle

```
draft → active → stopped
```

Reject invalid transitions with 422:

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## Deterministic Variant Assignment

Users must always land in the same variant — use `crc32` for a reproducible, stateless bucket:

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

The DB stores the assignment on first call; subsequent calls return the stored variant — determinism + DB truth.

## Idempotent Assignment

```php
// Return existing assignment without re-rolling
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200, not 201
}
// First time: compute and store
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## Results Aggregation (CVR)

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

Then compute CVR in PHP:

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## Guard Rails

- Only `active` experiments accept assignments (409 otherwise).
- Events require the user to be assigned (404 otherwise).
- `UNIQUE(experiment_id, user_id)` prevents double-assignment at the DB level.
- Weights must be positive integers; zero-weight variants are rejected (422).
