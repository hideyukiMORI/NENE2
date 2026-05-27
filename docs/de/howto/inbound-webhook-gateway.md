# How-to: Inbound-Webhook-Gateway

> **FT-Referenz**: FT317 (`NENE2-FT/inboundlog`) — Inbound-Webhook-Gateway mit HMAC-SHA256-Signaturverifizierung pro Quelle, Idempotenz via event_id-Deduplizierung, Secret wird niemals in Antworten exponiert, 17 Tests / 18 Assertions PASS.

Diese Anleitung zeigt, wie ein Multi-Source-Inbound-Webhook-Empfänger gebaut wird, der die Authentizität von Anfragen vor der Verarbeitung validiert.

## Schema

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- gemeinsames Secret für HMAC
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- vom Anbieter bereitgestellter Deduplizierungsschlüssel
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- roher JSON-Body
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/sources` | Eine neue Webhook-Quelle registrieren |
| `POST` | `/sources/{id}/receive` | Webhook-Event empfangen |
| `GET`  | `/sources/{id}/events` | Events für eine Quelle auflisten |
| `GET`  | `/events/{id}` | Einzelnes Event abrufen |

## Quellen-Registrierung

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// secret wird NIEMALS zurückgegeben
```

```php
POST /sources  {"secret": "abc"}   → 422  // name erforderlich
POST /sources  {"name": "github"}  → 422  // secret erforderlich
```

## HMAC-SHA256-Signaturverifizierung

Jeder eingehende Webhook muss einen `X-Webhook-Signature`-Header mit dem HMAC-SHA256 des rohen Bodys enthalten:

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // zeitkonstanter Vergleich
}
```

**Wichtig**: `hash_equals()` verwenden — nicht `===` — um Timing-Angriffe zu verhindern.

## Events empfangen

```php
// Absender (z.B. Stripe) berechnet:
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### Fehlerfälle

```php
// Falsche oder fehlende Signatur
POST /sources/1/receive  (ungültige Signatur)  → 401 Unauthorized

// Quelle nicht gefunden
POST /sources/9999/receive                     → 404 Not Found

// Fehlende event_id im Payload
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## Doppeltes Event — Idempotenz

Anbieter-Wiederholungsversuche sind üblich — `event_id`-Deduplizierung verhindert Doppelverarbeitung:

```php
// Erste Zustellung
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// Wiederholungsversuch (gleiche event_id)
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

`UNIQUE(source_id, event_id)` in der DB erzwingt dies auf Speicherebene.

## Events abfragen

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `secret` in der Quellantwort zurückgeben | Gibt den Signierschlüssel an jeden Client weiter, der die API-Antwort lesen kann |
| `===` statt `hash_equals()` für Signatur verwenden | Timing-Angriff enthüllt HMAC Byte für Byte |
| Kein `event_id`-Dedup | Anbieter-Wiederholungsversuche verursachen Doppelverarbeitung (doppelte Belastungen, doppelte E-Mails) |
| Signatur nach JSON-Parsing verifizieren | Angreifer kann Body so konstruieren, dass er JSON-Parsing besteht, aber HMAC fehlschlägt; immer zuerst rohe Bytes verifizieren |
| Ein einzelnes globales Secret für alle Quellen | Kompromittierung einer Integration exponiert alle |
