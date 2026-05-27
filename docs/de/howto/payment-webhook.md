# Implementierungsanleitung für Payment-Webhook-Empfang

## Übersicht

Diese Anleitung erklärt, wie eine Payment-Webhook-Empfangs-API mit NENE2 implementiert wird.
Sie bietet HMAC-SHA256-Signaturverifizierung, idempotente Verarbeitung (event_id UNIQUE-Constraint) und Status-Übergangs-Schutz.

---

## DB-Schema

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,               -- kleinste Währungseinheit (Yen, Cent)
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,   -- Idempotenz-Schlüssel
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,          -- JSON
    processed_at TEXT    NOT NULL
);
```

`webhook_events.event_id` ist der Kern der **idempotenten Verarbeitung**. Dieselbe event_id wird auch bei zweimaligem Empfang nur einmal verarbeitet.

---

## Endpunktdesign

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/webhooks/payment` | Webhook-Event empfangen und verarbeiten |
| GET | `/payments` | Zahlungsliste |
| GET | `/payments/{id}` | Zahlungsdetails |

---

## Status-Übergänge

```
[erstellt] → pending → succeeded → refunded
                     ↘ failed
```

Verwaltung über Übergangstabelle:

```php
private const array VALID_TRANSITIONS = [
    'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
    'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
    'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
];
```

Ungültige Übergänge (z.B. failed → succeeded) geben 409 Conflict zurück.

---

## Designpunkte

### HMAC-SHA256-Signaturverifizierung

Den gesamten Request-Body mit HMAC-SHA256 verifizieren. Stripe-kompatibler `X-Webhook-Signature: sha256=<hex>`-Header wird verwendet:

```php
private function verifySignature(string $body, string $header): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $body, $this->webhookSecret);
    return hash_equals($expected, $provided); // Timing-Angriffs-Prävention
}
```

`hash_equals()` für zeitkonstanten Vergleich verwenden. `===` und `strcmp()` brechen früh ab und sind daher anfällig.

### Idempotente Verarbeitung

Webhook-Provider wiederholen. Duplikate mit `event_id` eliminieren:

```php
// Vor Verarbeitung prüfen
if ($this->repo->isEventProcessed($eventId)) {
    return $this->json->create(['status' => 'already_processed']);
}

// Nach Verarbeitung aufzeichnen
$this->repo->recordEvent($eventId, $eventType, $payload, $now);
```

### Verarbeitungsreihenfolge

```
1. Signaturverifizierung → 401
2. Duplikatprüfung mit event_id → 200 already_processed
3. Verarbeitung nach Event-Typ
4. In webhook_events aufzeichnen
5. 200 processed zurückgeben
```

**Signaturverifizierung zuerst durchführen**, um zu verhindern, dass Angreifer die event_id-Tabelle verschmutzen.

### Unbekannte Event-Typen mit 200 zurückgeben

Wenn ein Provider einen neuen Event-Typ hinzufügt, verursacht die Rückgabe von 4xx Wiederholungsversuche.
Unbekannte Typen still mit 200 zurückgeben und aufzeichnen:

```php
// Unbekannter Event-Typ — bestätigen ohne Verarbeitung
return null; // → 200 processed
```

### Test: Signatur mit injiziertem SECRET generieren

```php
private const string SECRET = 'test-webhook-secret';

private function signedReq(string $path, array $body): ResponseInterface
{
    $rawBody = json_encode($body);
    $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    // ...
}
```

`AppFactory::createSqlite($dbFile, self::SECRET)` verwendet dasselbe Secret für die App.

---

## Beispiel-Event-Payloads

### payment.created

```json
{
  "event_id": "evt_001",
  "event_type": "payment.created",
  "data": {"id": "pay_abc", "amount": 5000, "currency": "jpy"}
}
```

### payment.succeeded

```json
{
  "event_id": "evt_002",
  "event_type": "payment.succeeded",
  "data": {"id": "pay_abc"}
}
```

### Antwort (Erfolg)

```json
{"status": "processed", "event_type": "payment.succeeded"}
```

### Antwort (idempotenter Wiederholungsversuch)

```json
{"status": "already_processed"}
```

---

## Referenzimplementierung

`../NENE2-FT/paymentlog/` — FT163 Feldversuch (18 Tests, Signaturverifizierung, idempotente Verarbeitung, Übergangs-Schutz)
