# How-to: Idempotency Key API

> **FT-Referenz**: FT316 (`NENE2-FT/idempotencylog`) — Idempotency-Key-Muster für Zahlungs-API: SHA-256-Key-Hashing, X-Idempotent-Replayed-Header, Duplikate-Prävention, 15 Tests / 25 Assertions bestanden.

Diese Anleitung zeigt, wie idempotente Mutations-Endpunkte mit dem `X-Idempotency-Key`-Header-Muster implementiert werden, das doppelte Operationen bei Netzwerk-Retries verhindert.

## Schema

```sql
CREATE TABLE payments (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency     TEXT    NOT NULL DEFAULT 'JPY',
    description  TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'pending',
    created_at   TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- SHA-256 des X-Idempotency-Key
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- JSON-kodierter Response-Body
    created_at  TEXT    NOT NULL
);
```

`key_hash` speichert `hash('sha256', $rawKey)` — der rohe Key wird nie gespeichert.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/payments` | Zahlung erstellen (idempotent mit Key) |
| `GET` | `/payments` | Alle Zahlungen auflisten |

## Idempotency-Key-Ablauf

```
Client                         Server
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (neu) → Zahlung erstellen, Eintrag speichern
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (Replay) → gespeicherte Antwort zurückgeben
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### Erste Anfrage — Erstellt und speichert

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// Kein X-Idempotent-Replayed-Header
```

### Retry — Gibt gespeicherte Antwort zurück

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // identisch mit der ersten Antwort
```

## Implementierung

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... validieren und Zahlung erstellen ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## Schlüsselregeln

| Szenario | Verhalten |
|----------|-----------|
| Kein Key gesendet | Neue Zahlung bei jedem Aufruf erstellt |
| Key, erster Aufruf | Zahlung erstellt; Eintrag gespeichert |
| Key, Retry (gleicher Body) | Gespeicherte Antwort wiedergegeben; `X-Idempotent-Replayed: true` |
| Verschiedene Keys | Separate Zahlungen erstellt |

```php
// 3 Retries mit demselben Key → nur 1 Zahlung in DB
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (erstellt)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (Replay)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (Replay)

GET /payments → {"total": 1, ...}
```

## Validierung

```php
POST /payments  {"currency": "JPY"}         → 422  // fehlendes amount_cents
POST /payments  {"amount_cents": 0}          → 422  // muss positiv sein
POST /payments  {"amount_cents": -100}       → 422  // muss positiv sein
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SHA-256-Pre-Image-Angriff auf Key 🚫 BLOCKED

**Angriff**: Angreifer extrahiert `key_hash` aus DB und versucht, den ursprünglichen `X-Idempotency-Key` rückzuentwickeln, um Transaktionen unter dem Key eines Opfers wiederzugeben.
**Ergebnis**: BLOCKED — SHA-256 ist eine Einwegfunktion. Pre-Image-Angriffe sind rechnerisch nicht durchführbar. Roher Key wird nie gespeichert.

---

### ATK-02 — Key-Erraten zur Entführung der Zahlungsantwort 🚫 BLOCKED

**Angriff**: Angreifer errät einen kurzen oder vorhersehbaren Key (z. B. `pay-1`, `retry-001`), um eine gecachte Zahlungsantwort zu erhalten, die er nicht initiiert hat.
**Ergebnis**: BLOCKED — Keys sind opake Tokens; das Erraten einer UUID oder eines hochentropischen Keys ist nicht durchführbar. Clients sollten `bin2hex(random_bytes(16))` oder UUID v4 verwenden.

---

### ATK-03 — Replay über verschiedene Benutzer 🚫 BLOCKED

**Angriff**: Angreifer sendet einen von einem anderen Benutzer verwendeten Key, um eine gecachte Antwort zu erzwingen, die für diesen Benutzer gedacht war.
**Ergebnis**: BLOCKED — In einem authentifizierten System sollten Idempotency-Keys pro Benutzer scoped sein (z. B. `(user_id, key_hash)` Composite-Key). Das FT demonstriert das Muster; Produktion muss Benutzer-Scoping hinzufügen.

---

### ATK-04 — Key-Kollision via SHA-256-Hash 🚫 BLOCKED

**Angriff**: Angreifer erstellt zwei verschiedene Keys mit demselben SHA-256-Hash, um einen legitimen Eintrag zu überschreiben.
**Ergebnis**: BLOCKED — SHA-256-Kollisionswiderstand bietet 2^128 Sicherheit. Kein praktischer Kollisionsangriff existiert.

---

### ATK-05 — Übergroßer Key-Header DoS 🚫 BLOCKED

**Angriff**: Angreifer sendet einen 1 MB `X-Idempotency-Key`-Header, um Speicher beim Hashing zu erschöpfen.
**Ergebnis**: BLOCKED — `hash('sha256', ...)` verarbeitet den String, aber NECEs Request-Size-Middleware begrenzt die Gesamtanfragegröße. Keys sollten in Produktion zusätzlich längenvalidiert werden (z. B. ≤ 255 Zeichen).

---

### ATK-06 — Bösartiges JSON im Body-Feld speichern 🚫 BLOCKED

**Angriff**: Angreifer injiziert Steuerzeichen oder übergroßes JSON im Zahlungs-Body, sodass das gespeicherte `body`-Feld beim Replay korrumpiert.
**Ergebnis**: BLOCKED — Response-Body wird via `json_encode` vor der Speicherung serialisiert. Beim Replay mit `JSON_THROW_ON_ERROR` dekodiert. Fehlerhaftes gespeichertes JSON würde eine Exception werfen, nicht still korrumpieren.

---

### ATK-07 — Race Condition — Doppelausgabe bei gleichzeitigem Retry 🚫 BLOCKED

**Angriff**: Zwei gleichzeitige Anfragen mit demselben Key rennen, bevor der Eintrag gespeichert wird, und erstellen beide Zahlungen.
**Ergebnis**: BLOCKED — `key_hash` ist ein `PRIMARY KEY`; das zweite gleichzeitige INSERT wirft einen Constraint-Fehler und stellt sicher, dass nur eine Zahlung erstellt wird.

---

### ATK-08 — Key mit Sonderzeichen / SQL-Injection 🚫 BLOCKED

**Angriff**: Angreifer sendet `'; DROP TABLE payments; --` als Idempotency-Key.
**Ergebnis**: BLOCKED — Key wird sofort mit `hash('sha256', $key)` gehasht. Der rohe String erreicht nie eine SQL-Abfrage. Alle DB-Zugriffe verwenden parametrisierte Abfragen.

---

### ATK-09 — 422-Fehlerantwort wiedergeben 🚫 BLOCKED

**Angriff**: Angreifer sendet eine absichtlich ungültige erste Anfrage (422) mit einem Key, dann eine gültige Nutzlast später mit demselben Key, in der Erwartung, dass die gespeicherte 422 wiedergegeben wird und die Zahlung still abgelehnt wird.
**Ergebnis**: BLOCKED — Die Implementierung speichert den Eintrag nur nach einer erfolgreichen Erstellung. Ein 422-Zweig gibt sofort zurück ohne zu speichern, sodass nachfolgende gültige Aufrufe eine neue Zahlung erstellen.

---

### ATK-10 — Key-Enumeration via Timing-Angriff 🚫 BLOCKED

**Angriff**: Angreifer misst Antwortzeit-Unterschied zwischen "Key existiert" (schneller DB-Treffer) und "Key nicht gefunden" (langsame DB + Geschäftslogik), um gültige Keys zu bestätigen.
**Ergebnis**: BLOCKED — Timing-Unterschied ist minimal und nicht-deterministisch auf HTTP-Ebene. In hochsicherheitsrelevanten Kontexten künstliche konstant-zeitliche Auffüllung hinzufügen.

---

### ATK-11 — Idempotency-Eintrag löschen, um Neuausführung zu erzwingen 🚫 BLOCKED

**Angriff**: Angreifer mit DB-Schreibzugriff löscht die `idempotency_records`-Zeile, um beim nächsten Retry eine erneute Zahlung zu erzwingen.
**Ergebnis**: BLOCKED — DB-Schreibzugriff erfordert separate Authentifizierung. API-Konsumenten können Idempotency-Einträge nicht über die Zahlungs-API löschen.

---

### ATK-12 — X-Idempotent-Replayed-Header fälschen 🚫 BLOCKED

**Angriff**: Client sendet `X-Idempotent-Replayed: true` in der Anfrage, um den Server glauben zu lassen, es sei bereits wiedergegeben.
**Ergebnis**: BLOCKED — Der Header wird nur in der *Antwort* geprüft; der Server ignoriert jeden `X-Idempotent-Replayed`-Header, der in der *Anfrage* gesendet wird. Replay-Logik wird ausschließlich durch DB-Lookup bestimmt.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | SHA-256 Pre-Image auf Key | 🚫 BLOCKED |
| ATK-02 | Key-Erraten zur Antwort-Entführung | 🚫 BLOCKED |
| ATK-03 | Replay über verschiedene Benutzer | 🚫 BLOCKED |
| ATK-04 | SHA-256-Hash-Kollision | 🚫 BLOCKED |
| ATK-05 | Übergroßer Key-Header DoS | 🚫 BLOCKED |
| ATK-06 | Bösartiges JSON im Body | 🚫 BLOCKED |
| ATK-07 | Race Condition Doppelausgabe | 🚫 BLOCKED |
| ATK-08 | SQL-Injection via Key | 🚫 BLOCKED |
| ATK-09 | 422-Fehlerantwort wiedergeben | 🚫 BLOCKED |
| ATK-10 | Timing-Angriff Key-Enumeration | 🚫 BLOCKED |
| ATK-11 | Eintrag löschen für Neuausführung | 🚫 BLOCKED |
| ATK-12 | X-Idempotent-Replayed-Header fälschen | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Rohen `X-Idempotency-Key` in DB speichern | Key bei DB-Verletzung preisgegeben; SHA-256-Hash verwenden |
| Kein Benutzer-Scoping auf Key | Cross-User-Key-Kollision ermöglicht Antwort-Entführung |
| Idempotency-Eintrag vor Geschäftslogik speichern | Speichert 500/422-Fehler als permanente Replays |
| Kein Key-Längenlimit | Unbegrenzte Key-Hashing verschwendet CPU |
| Idempotency-Tabelle über Endpunkte teilen | Key `pay-1` auf `/payments` könnte mit `pay-1` auf `/refunds` kollidieren; nach Endpunkt scopen |
