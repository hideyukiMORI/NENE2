# Event Sourcing (Grundlagen)

Zustand als unveränderliche Sequenz von Domain-Events persistieren. Aktuellen Zustand durch Wiedergabe des Event-Streams ableiten.

## Überblick

Event Sourcing speichert **was passiert ist** (Events) statt **was ist** (aktueller Zustand). Der Kontostand wird nicht gespeichert; er wird durch Wiedergabe aller Einzahlungs- und Abhebungs-Events berechnet. Events sind unveränderlich — sie werden weder aktualisiert noch gelöscht.

## Datenbankschema

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
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` ist der Aggregate-Root. `events` ist das Append-Only-Event-Log. Es gibt keine `balance`-Spalte — sie wird immer aus Events berechnet.

## Event-Typen

Event-Typen als Konstanten definieren, um Tippfehler zu verhindern und statische Analyse zu ermöglichen:

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## Events anhängen

Events werden immer eingefügt, niemals aktualisiert. Die API hat keinen Endpunkt, der Events ändert oder löscht:

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## Zustand wiedergeben

Events in Einfügereihenfolge laden und zum aktuellen Zustand falten:

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

`ORDER BY id ASC` garantiert die Wiedergabereihenfolge. `ORDER BY occurred_at ASC` ist fragil — zwei Events mit demselben Zeitstempel hätten eine undefinierte Reihenfolge.

## Betragsvalidierung

Beträge vor dem Anhängen von Events streng validieren:

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` lehnt Float-Werte ab (z. B. `1.9`), die PHP sonst stillschweigend auf `1` kürzen würde
- Die Obergrenze verhindert Integer-Überlauf beim Summieren mehrerer großer Einzahlungen
- Auf API-Ebene ablehnen — ungültige Beträge nicht das Event-Log erreichen lassen

## Unzureichende Mittel

Kontostand vor dem Anhängen eines Abhebungs-Events prüfen:

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

Die Kontostandprüfung erfolgt im Handler (nicht im Repository), weil es sich um eine Geschäftsregel handelt, keine Datenintegritätsbeschränkung.

## Event-Isolation

Events sind durch `aggregate_id` auf ihr Aggregat beschränkt. Die Wiedergabe der Events von Konto A berührt niemals Konto B:

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|---|---|
| Event-Unveränderlichkeit | Kein DELETE/UPDATE-Endpunkt für Events |
| Betragsbereich | 1–1.000.000.000 (int) — lehnt Floats und Überlauf-Werte ab |
| Unzureichende Mittel | Kontostand vor Abhebung wiedergegeben; 422 wenn unzureichend |
| Cross-Account-Isolation | Alle Abfragen filtern nach aggregate_id |
| Payload-Injection | Payload immer `['amount' => int]`; keine benutzerkontrollierten Schlüssel |
| Event-Typ-Injection | Event-Typ immer aus Konstanten; kein benutzerkontrollierter event_type |

## Routen-Übersicht

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/accounts` | Konto erstellen |
| `POST` | `/accounts/{id}/deposit` | Einzahlungs-Event anhängen |
| `POST` | `/accounts/{id}/withdraw` | Abhebungs-Event anhängen (Kontostand-Prüfung) |
| `GET` | `/accounts/{id}/balance` | Kontostand aus Events wiedergeben |
| `GET` | `/accounts/{id}/events` | Alle Events für Konto auflisten |
