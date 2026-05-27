# How-to: Event Sourcing & CQRS API

> **FT-Referenz**: `NENE2-FT/eventstore` — Append-Only-Event-Log mit Per-Aggregat-Sequenznummern, Allowlist für Event-Typen, Read-Model-Projektion aus Events neu aufgebaut, Kontostandverfolgung, 17 Tests PASS.

Diese Anleitung zeigt, wie Event Sourcing implementiert wird: Jede Zustandsänderung als unveränderliches Event speichern, den aktuellen Zustand aus dem Event-Log berechnen und eine Read-Model-Projektion bereitstellen.

## Schema

```sql
-- Append-Only-Event-Log — niemals UPDATE oder DELETE auf Zeilen
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- z. B. "acc-001"
    aggregate_type TEXT    NOT NULL,  -- z. B. "account"
    event_type     TEXT    NOT NULL,  -- z. B. "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- Per-Aggregat-Zähler, beginnt bei 1
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- Read-Model: aktueller Kontostatus aus Events neu aufgebaut
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` verhindert doppelte Event-Einfügungen. Die Projektion wird immer aus dem Event-Log abgeleitet — sie kann jederzeit durch Wiedergabe neu aufgebaut werden.

## Event-Typen (Allowlist)

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

Unbekannte Event-Typen geben 422 zurück. Nur Events anhängen, die explizite Handler in der Projektionslogik haben.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|-------------------------------|---------------------------------|
| `POST` | `/accounts`                   | Konto eröffnen (sendet AccountOpened) |
| `GET`  | `/accounts`                   | Alle Kontoprojektionen auflisten |
| `GET`  | `/accounts/{id}`              | Kontoprojektion abrufen (404 wenn nicht gefunden) |
| `POST` | `/accounts/{id}/events`       | Event an Konto anhängen |
| `GET`  | `/accounts/{id}/events`       | Event-Log für Konto auflisten |

## Konto eröffnen

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

Das Eröffnen eines Kontos erstellt ein `AccountOpened`-Event (sequence=1) und initialisiert die Projektion.

## Events anhängen

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← erhöht sich pro Aggregat
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

Jedes Konto hat einen **unabhängigen Sequenzzähler**. `acc-001` und `acc-002` beginnen beide bei 1.

```php
// Ungültiger Event-Typ → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// Nicht-existierendes Konto → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## Read-Model-Projektion

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 eingezahlt + 10000 eingezahlt
  "is_open": true,
  "last_sequence": 3
}

// AccountClosed-Event angewendet
GET /accounts/acc-001  // nach Anhängen von AccountClosed
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## Event-Log

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

Nach `sequence ASC` sortiert — chronologische Reihenfolge.

```php
// Unbekanntes Konto → leere Liste (nicht 404)
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## Implementierung

### Sequenz-Generierung

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### Event anhängen + Projektion aktualisieren (Transaktion)

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // An unveränderliches Log anhängen
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // Projektion aktualisieren
        $this->applyEventToProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }
    ...
}
```

## Projektion-Anwendungslogik

```php
private function applyEventToProjection(string $aggregateId, string $eventType, array $payload, int $sequence, \DateTimeImmutable $now): void
{
    match ($eventType) {
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [(int) ($payload['amount_cents'] ?? 0), $sequence, $now->format('Y-m-d H:i:s'), $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [(int) ($payload['amount_cents'] ?? 0), $sequence, $now->format('Y-m-d H:i:s'), $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $now->format('Y-m-d H:i:s'), $aggregateId],
        ),
        default => null,
    };
}
```

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Events aktualisieren oder löschen | Verletzt Event-Sourcing-Unveränderlichkeit; macht Audit-Trail unzuverlässig |
| `MAX(sequence)` ohne Transaktion | Race-Condition: zwei gleichzeitige Anhänge bekommen dieselbe Sequenznummer; UNIQUE-Constraint fängt es ab |
| Projektion ohne Transaktion aktualisieren | Event wurde angehängt, aber Projektion nicht aktualisiert; inkonsistenter Zustand |
| Beliebige Event-Typen ohne Allowlist akzeptieren | Ungültige Events verunreinigen das Log; machen Projektions-Handler komplex |
| Projektion als einzige Quelle der Wahrheit behandeln | Projektion kann aus Events neu aufgebaut werden; das Log ist die kanonische Quelle |
