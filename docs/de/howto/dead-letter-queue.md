# How-to: Dead Letter Queue (DLQ)

> **FT-Referenz**: FT72 (`NENE2-FT/deadletterlog`) — Dead Letter Queue API

Demonstriert eine zuverlässige Nachrichtenwarteschlange mit exponentiellen Backoff-Retries und einer Dead Letter Queue. Fehlgeschlagene Nachrichten werden automatisch mit zunehmenden Verzögerungen neu geplant; nach dem Erschöpfen aller Retries wechseln sie in einen `dead`-Zustand, wo sie inspiziert und wiedergegeben werden können. Unterstützt mehrere benannte Warteschlangen über Pfadparameter.

---

## Nachrichtenlebenszyklus

```
enqueue ──▶ pending ──claim──▶ processing
                                    │
                        ┌──succeed──┤──fail (Retries verbleibend)──▶ pending (retry_after)
                        │           │
                        ▼           └──fail (erschöpft)──▶ dead ──replay──▶ pending
                    succeeded
```

| Status | Beschreibung |
|--------|--------------|
| `pending` | Kann beansprucht werden (oder wartet bis `retry_after`) |
| `processing` | Von einem Worker beansprucht, wird verarbeitet |
| `succeeded` | Erfolgreich abgeschlossen |
| `dead` | Alle Retries erschöpft — in der Dead Letter Queue |

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/queues/{queue}/messages` | Nachricht in Warteschlange einreihen |
| `GET` | `/queues/{queue}/messages` | Nachrichten in einer Warteschlange auflisten |
| `GET` | `/queues/{queue}/messages/{id}` | Einzelne Nachricht abrufen |
| `POST` | `/queues/{queue}/claim` | Nächste ausstehende Nachricht beanspruchen |
| `POST` | `/queues/{queue}/messages/{id}/succeed` | Als erfolgreich markieren |
| `POST` | `/queues/{queue}/messages/{id}/fail` | Als fehlgeschlagen markieren (Retry oder DLQ) |
| `POST` | `/queues/{queue}/messages/{id}/replay` | Tote Nachricht wiedergeben |

---

## Nachricht in Warteschlange einreihen

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // Erforderlicher String
    'max_retries' => 5,  // Optional, Standard 3, Bereich 1–10
];
```

`max_retries` wird validiert, zwischen 1 und 10 zu sein:

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## Nächste ausstehende Nachricht beanspruchen

Ein Worker ruft `POST /queues/{queue}/claim` auf, um eine Nachricht atomar aus der Warteschlange zu nehmen:

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // Keine Nachricht verfügbar
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` filtert Nachrichten heraus, die zwischen Retries warten. Nachrichten werden in FIFO-Reihenfolge beansprucht (`ORDER BY created_at ASC`).

> **Atomizitätshinweis**: Ohne Transaktion können zwei gleichzeitige Worker dieselbe Nachricht beanspruchen, wenn beide dieselbe Zeile lesen, bevor eines der UPDATEs ausgeführt wird. SELECT + UPDATE in einer Transaktion mit `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) einwickeln oder `UPDATE ... WHERE status = 'pending' RETURNING id` für echten atomaren Claim verwenden.

---

## Fehlerbehandlung mit exponentiellem Backoff

Wenn ein Worker einen Fehler meldet (`POST .../fail`), plant das Repository entweder einen Retry oder bewegt die Nachricht in die Dead Letter Queue:

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // Erschöpft — in DLQ verschieben
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // Retry mit exponentiellem Backoff planen
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### Backoff-Zeitplan (max_retries = 5)

| Versuch | Backoff-Sekunden | Formel |
|---------|-----------------|--------|
| 1. Fehler | 2 s | 2^1 |
| 2. Fehler | 4 s | 2^2 |
| 3. Fehler | 8 s | 2^3 |
| 4. Fehler | 16 s | 2^4 |
| 5. Fehler | → dead | Retries erschöpft |

`min(2 ** $newRetryCount, 3600)` begrenzt den maximalen Backoff auf 1 Stunde. Für große Retry-Zahlen verhindert dies mehrtägige Verzögerungen, gibt dem Dienst aber trotzdem Zeit zur Erholung.

---

## Tote Nachrichten wiedergeben

Eine tote Nachricht kann durch Zurücksetzen auf `pending` mit geleerten Retry-Zustand wiedergegeben werden:

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` wird auf 0 zurückgesetzt, sodass die Nachricht das vollständige `max_retries`-Budget erneut erhält. Der ursprüngliche `max_retries`-Wert bleibt erhalten.

> **Best Practice**: Vor der Wiedergabe die zugrunde liegende Fehlerursache beheben. Die Wiedergabe in ein defektes System wird die DLQ nur wieder befüllen.

---

## Mehrere benannte Warteschlangen

Der `{queue}`-Pfadparameter leitet Nachrichten nach Namen weiter. Jeder nicht-leere String ist gültig:

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

Alle Abfragen filtern nach `queue = ?`, sodass jede Warteschlange isoliert ist. Kein Warteschlangen-Registrierungsschritt ist notwendig — Warteschlangen werden implizit beim ersten Einreihen erstellt.

---

## Schema

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- NULL wenn nicht für Retry geplant
    last_error  TEXT,           -- NULL bis zum ersten Fehler
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

Wichtige Design-Entscheidungen:
- `payload` ist ein undurchsichtiger String — die Warteschlange inspiziert oder validiert den Nachrichteninhalt nicht.
- `last_error` speichert die jüngste Fehlermeldung für Debugging.
- `retry_after` ist `NULL` für neue Nachrichten und wird bei Wiedergabe geleert, sodass `retry_after <= now` ohne Sonderbehandlung funktioniert.

---

## Worker-Muster

Ein Worker fragt Nachrichten ab und verarbeitet eine nach der anderen:

```php
// Worker-Schleife (Pseudocode)
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // Keine Nachrichten, zurückwarten
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

Claim-zu-Succeed/Fail-Zyklen kurz halten. Langanhaltende Verarbeitung ohne Timeouts lässt Nachrichten dauerhaft im `processing`-Zustand, wenn der Worker abstürzt. Eine `processing_timeout`-Spalte und einen Reaper-Job hinzufügen, um timed-out-Nachrichten zurückzufordern.

---

## Verwandte Anleitungen

- [`job-queue.md`](job-queue.md) — Basis-Job-Warteschlange ohne DLQ
- [`notification-queue.md`](notification-queue.md) — Benachrichtigungs-Warteschlangen-Muster
- [`idempotency.md`](idempotency.md) — Idempotente Verarbeitung für At-Least-Once-Delivery
- [`webhook-delivery.md`](webhook-delivery.md) — Webhook-Retry-Muster
