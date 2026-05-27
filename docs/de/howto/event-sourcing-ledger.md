# How-to: Event-Sourcing-Hauptbuch

> **FT-Referenz**: FT310 (`NENE2-FT/eventsourcelog`) — Event-Sourcing-Kontobuch: unveränderliches Event-Log (Append-Only), `replayBalance()` gibt alle Events wieder, um den aktuellen Kontostand zu berechnen, Einzahlungs-/Abhebungs-Events werden niemals gelöscht, strenge Betragsvalidierung mit `is_int()`, max. Betrag 1.000.000.000, separate Konten teilen keinen Saldo, 17 Tests / 24 Assertions PASS.

Diese Anleitung zeigt, wie ein Kontobuch mit Event Sourcing implementiert wird: Der aktuelle Kontostand wird nicht direkt gespeichert — er wird durch Wiedergabe aller vergangenen Events abgeleitet.

## Schema

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON: { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` ist Append-Only. Es gibt kein `UPDATE` oder `DELETE` für Events. Jede Einzahlung oder Abhebung hängt eine neue Zeile an.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/accounts` | Konto erstellen |
| `GET` | `/accounts/{id}/balance` | Aktuellen Kontostand abrufen (wiedergegeben) |
| `POST` | `/accounts/{id}/deposit` | Einzahlungs-Event anhängen |
| `POST` | `/accounts/{id}/withdraw` | Abhebungs-Event anhängen |
| `GET` | `/accounts/{id}/events` | Alle Events auflisten |

## Kontostand — Aus Events wiedergeben

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

Der Kontostand wird nirgends gespeichert — er wird durch Wiedergabe aller Events frisch berechnet. Neue Konten starten bei 0 (keine Events). Das Event-Log ist die Quelle der Wahrheit.

## Betragsvalidierung — `is_int()` ist zwingend erforderlich

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount <= 0 || $amount > 1_000_000_000) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer up to 1,000,000,000'], 422);
}
```

- `is_int()` lehnt String-kodierte Zahlen (`"100"`) und Floats (`1.5`) ab
- Float-Beträge würden zu falschen Cent-Berechnungen führen, wenn sie als Integer behandelt werden
- Die Obergrenze von 1.000.000.000 verhindert Integer-Überlauf beim Summieren vieler Events

## Abhebung — Kontostandprüfung vor dem Event

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->responseFactory->create(['error' => 'insufficient funds'], 422);
}

$event = $this->repo->appendEvent($id, 'withdrawn', ['amount' => $amount], $now);
```

Der Kontostand wird jedes Mal durch vollständige Wiedergabe berechnet. Dies ist idempotent-sicher: kein Race-Condition-Problem für einzelne Konten unter SQLites Zeilen-Sperr-Semantik.

## Separate Konten teilen keinen Saldo

```php
public function findEventsByAggregateId(int $aggregateId): array
{
    // Filtert immer nach aggregate_id — greift niemals auf Events anderer Konten zu
    $rows = $this->executor->fetchAll(
        'SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC',
        [$aggregateId],
    );
    ...
}
```

`ORDER BY id ASC` (nicht `occurred_at ASC`) garantiert die deterministische Wiedergabereihenfolge auch dann, wenn zwei Events dieselbe Sekunde haben.

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Kontostand als Spalte in `accounts` speichern | Wird inkonsistent mit dem Event-Log wenn Events direkt eingefügt werden |
| `ORDER BY occurred_at ASC` für die Wiedergabe | Nicht deterministisch — zwei Events in derselben Sekunde haben undefinierte Reihenfolge |
| Float-Beträge akzeptieren | `1.9` wird als PHP-Int zu `1` — der Cent-Betrag geht verloren |
| `amount` aus dem Request-Body ohne `is_int()`-Prüfung | String `"100"` wird als ungültig behandelt, kann aber Validierungen in einigen Kontexten umgehen |
| Events aktualisieren oder löschen | Verletzt Event-Sourcing-Unveränderlichkeitsgarantie; macht Audit-Trail unzuverlässig |
