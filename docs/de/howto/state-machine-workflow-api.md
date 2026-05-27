# How-to: Zustandsautomaten-Workflow-API

> **FT-Referenz**: FT349 (`NENE2-FT/workflowlog`) — Zustandsautomaten-Workflow-Instanzen mit
> fest codierter Übergangs-Map, `allowed_next` in Antworten, Übergangshistorie-Protokoll, Erzwingung
> terminaler Zustände, Status-Filter bei Liste; 13 Tests PASS.

Diese Anleitung zeigt, wie eine Workflow-Engine mit einem Zustandsautomaten aufgebaut wird: erlaubte
Zustandsübergänge definieren, Workflow-Instanzen erstellen, sie mit Akteur-Zuordnung durch Zustände
treiben und die vollständige Überganghistorie protokollieren.

## Schema

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- z.B. "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## Workflow-Definition — „order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled (terminal)
        │                ├──► rejected   (terminal)
        └──► cancelled   └──► cancelled  (terminal)
        (terminal)
```

| Von Zustand | Erlaubte Nächste Zustände             |
|-------------|---------------------------------------|
| `draft`     | `submitted`, `cancelled`              |
| `submitted` | `approved`, `cancelled`, `rejected`   |
| `approved`  | `fulfilled`                           |
| `fulfilled` | _(terminal — keine)_                  |
| `cancelled` | _(terminal — keine)_                  |
| `rejected`  | _(terminal — keine)_                  |

## Endpunkte

| Methode | Pfad                                           | Beschreibung                   |
|---------|------------------------------------------------|-------------------------------|
| `POST`  | `/workflows/{workflow}/instances`              | Workflow-Instanz erstellen     |
| `GET`   | `/workflows/{workflow}/instances`              | Instanzen auflisten            |
| `GET`   | `/workflows/{workflow}/instances/{id}`         | Instanz mit Historie abrufen   |
| `POST`  | `/workflows/{workflow}/instances/{id}/transition` | Zustandsübergang auslösen  |

## Instanz erstellen

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← nächste gültige Zustände
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` wird aus der Übergangs-Map berechnet — reflektiert stets den aktuellen Zustand.

### Unbekannter Workflow → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // Workflow nicht definiert
```

## Instanzen auflisten

```php
// Alle Instanzen des "order"-Workflows
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// Nach aktuellem Zustand filtern
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // nur draft-Instanzen
```

## Instanz abrufen (mit Historie)

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` ist immer chronologisch geordnet (ASC nach `occurred_at`). Der Listen-Endpunkt lässt `history` für die Performance weg.

## Übergänge auslösen

```php
// Gültiger Übergang
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### Vollständiger Happy Path

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}    → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}  → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"} → fulfilled

// fulfilled ist terminal
→ {"current_state": "fulfilled", "allowed_next": [], ...}
```

### Ungültiger Übergang → 409

```php
// draft → approved (muss zuerst durch submitted)
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transition from 'draft' to 'approved' is not allowed"
}
```

### Terminaler Zustand → 409

```php
// cancelled ist terminal — keine Übergänge erlaubt
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" hat keine erlaubten Übergänge
```

## Implementierung

### WorkflowDefinition — Übergangs-Map

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // terminal
            'cancelled' => [],     // terminal
            'rejected'  => [],     // terminal
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Unknown workflow: {$workflow}"),
        };
    }
}
```

### Übergangs-Handler

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 ungültig oder terminal
    }

    // Atomar: Instanz aktualisieren + Übergangsproto einfügen
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` wird stets aus der Übergangs-Map berechnet, nie gespeichert — es bleibt konsistent mit `current_state`.

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `allowed_next` in DB speichern | Veraltete Daten wenn Übergangs-Map sich ändert; immer aus aktuellem Zustand berechnen |
| Freie `to_state`-Eingabe ohne Allowlist-Prüfung erlauben | Angreifer kann Zustand auf beliebigen Wert setzen und Workflow-Logik umgehen |
| Übergangsprotokollierung weglassen | Kein Audit-Protokoll; Workflow-Historie kann nicht rekonstruiert oder feststeckende Instanzen nicht debuggt werden |
| Terminale Zustände in `allowed_next` zurückgeben | Führt Aufrufer irre; terminale Zustände haben stets leeres `allowed_next` |
| 404 für ungültigen Übergang zurückgeben | 404 verbirgt den Unterschied zwischen „Instanz nicht gefunden" und „Übergang nicht erlaubt"; 409 für letzteren verwenden |
| Kein `workflow`-Feld in Instanzen-Tabelle | Instanzen verschiedener Workflow-Typen können nicht unterschieden werden; keine workflow-übergreifende Abfrage möglich |
