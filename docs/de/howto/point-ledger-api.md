# How-to: Punktkonto-API

> **FT-Referenz**: FT300 (`NENE2-FT/pointlog`) — Punktkonto-API: earn/spend/adjust/expire-Transaktionen, Saldo-Tracking, Überziehungsschutz (CHECK balance_after >= 0), nur Admin-Anpassung, reference_id-Idempotenz, MAX_EARN=10000 / MAX_ADJUST=100000 Obergrenzen, ATK-01~12 alle BLOCKED, 30 Tests / 66 Assertions PASS.

Diese Anleitung zeigt, wie ein Loyalitätspunkte-Konto erstellt wird, bei dem Benutzer Punkte verdienen und ausgeben, Admins Salden anpassen und Reference-IDs Doppeltransaktionen verhindern.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Drei CHECK-Constraints als Defense-in-Depth:
- `amount > 0` — keine Null- oder negativen Transaktionen auf DB-Ebene
- `balance_after >= 0` — Saldo kann im Speicher nie negativ werden
- `type IN (...)` — nur bekannte Transaktionstypen akzeptiert

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | Aktuellen Saldo abrufen |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | Transaktionshistorie abrufen |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id` (selbst) | Punkte verdienen |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id` (selbst) | Punkte ausgeben |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id` (admin) | Admin-Anpassung |

## Authentifizierung und Autorisierung

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

Jeder Handler ruft zuerst `requireUserId()` auf:

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

Benutzerübergreifender Zugriff wird dann für earn/spend geprüft:

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Admins können den Saldo oder die Historie eines beliebigen Benutzers anzeigen. Nicht-Admins können nur auf ihre eigenen zugreifen.

## Strenge Integer-Validierung

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` lehnt ab:
- Floats: `10.5` — abgelehnt (422)
- Strings: `"100"` — abgelehnt (422)
- Booleans: `true` — abgelehnt (422)
- Null: `0` — abgelehnt (amount <= 0)
- Negative: `-500` — abgelehnt (amount <= 0)

## Transaktionsobergrenzen

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Earn ist pro Transaktion auf 10.000 begrenzt. Admin-Adjust ist auf 100.000 begrenzt (höher, da es sich um eine privilegierte Korrekturoperation handelt).

## Überziehungsschutz

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

Aktuellen Saldo vor dem Abzug prüfen. Den aktuellen Saldo und den erforderlichen Betrag im Fehler zurückgeben, damit Clients dem Benutzer eine aussagekräftige Meldung anzeigen können.

## Admin-Anpassung

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

Adjust prüft `isAdmin()` **vor** der Prüfung des Zielbenutzers — ein Nicht-Admin erhält sofort 403, unabhängig vom Ziel. Das `adjust_type`-Feld (`'add'`-Standard / `'subtract'`) ermöglicht Admins, sowohl Punkte zu gewähren als auch abzuziehen, ohne separate Endpunkte zu benötigen.

## reference_id-Idempotenz

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

Wenn eine `reference_id` angegeben wird:
- Erster Aufruf → 201 Created mit neuer Transaktion
- Wiederholter Aufruf mit derselben `reference_id` → 200 OK mit der ursprünglichen Transaktion (keine neue Transaktion erstellt)

Dies verhindert Doppelgutschriften bei Netzwerk-Wiederholungsversuchen. Die reference_id-Suche ist **benutzergebunden** (`findByReferenceId($targetUserId, ...)`), sodass dieselbe reference_id von verschiedenen Benutzern ohne Konflikt verwendet werden kann.

## Saldoberechnung

```php
// Repository: Summe aller earn/adjust-add-Transaktionen minus spend/adjust-subtract/expire
public function getBalance(int $userId): int
{
    // Typischerweise: balance_after der letzten Transaktion, oder 0 wenn keine
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

Die `balance_after`-Spalte in jeder Transaktion speichert den laufenden Saldo. Den aktuellen Saldo zu erhalten ist eine einzelne `ORDER BY id DESC LIMIT 1`-Abfrage — keine SUM-Aggregation benötigt.

---

## ATK Assessment — Cracker-Mindset-Angriffstest

### ATK-01 — Nicht-authentifizierter Saldo-Zugriff 🚫 BLOCKED

**Angriff**: `GET /users/2/points` ohne `X-User-Id`-Header.
**Ergebnis**: BLOCKED — `requireUserId()` gibt null zurück → sofort 401. Keine Daten zurückgegeben.

---

### ATK-02 — Benutzerübergreifendes Saldo-Spähen 🚫 BLOCKED

**Angriff**: `GET /users/2/points` mit `X-User-Id: 3` (Alice versucht, Bobs Saldo zu lesen).
**Ergebnis**: BLOCKED — `$targetUserId (2) !== $actorId (3)` und kein Admin → 403.

---

### ATK-03 — Selbst-Gewährung an anderen Benutzer 🚫 BLOCKED

**Angriff**: `POST /users/3/points/earn` mit `X-User-Id: 2` und `amount: 99999`.
**Ergebnis**: BLOCKED — Akteur (2) != Ziel (3) und kein Admin → 403. Zielsaldo bleibt 0.

---

### ATK-04 — Negativer Betrag beim Verdienen 🚫 BLOCKED

**Angriff**: `POST /users/2/points/earn` mit `amount: -500`.
**Ergebnis**: BLOCKED — `$amount <= 0` Prüfung → 422. Saldo unverändert.

---

### ATK-05 — Null-Betrags-Transaktion 🚫 BLOCKED

**Angriff**: `POST /users/2/points/earn` mit `amount: 0` und separat `amount: 0` zum Ausgeben.
**Ergebnis**: BLOCKED — Beide geben 422 zurück (`amount <= 0`). Keine Null-Transaktionen erstellt.

---

### ATK-06 — Überziehungsausgabe 🚫 BLOCKED

**Angriff**: 100 Punkte verdienen, dann versuchen 101 auszugeben.
**Ergebnis**: BLOCKED — `$balance (100) < $amount (101)` → 422 mit `insufficient points`. Saldo bleibt 100. DB `CHECK (balance_after >= 0)` bietet zusätzliche Absicherung.

---

### ATK-07 — Normaler Benutzer passt an 🚫 BLOCKED

**Angriff**: `POST /users/2/points/adjust` mit `X-User-Id: 2` (Nicht-Admin-Rolle).
**Ergebnis**: BLOCKED — `isAdmin()`-Prüfung schlägt fehl → 403. Saldo bleibt 0.

---

### ATK-08 — Übermäßiger Earn-Betrag 🚫 BLOCKED

**Angriff**: `POST /users/2/points/earn` mit `amount: 10001` (über MAX_EARN=10000).
**Ergebnis**: BLOCKED — `$amount > MAX_EARN_PER_TRANSACTION` → 422 mit `max: 10000`. Saldo unverändert.

---

### ATK-09 — Doppelgutschrift via reference_id-Wiederverwendung 🚫 BLOCKED

**Angriff**: 500 Punkte mit `reference_id: "order-999"` verdienen, dann dieselbe Anfrage wiederholen.
**Ergebnis**: BLOCKED — Zweiter Aufruf findet vorhandene Transaktion via `findByReferenceId()` → 200 mit derselben Transaktion. Saldo bleibt 500 (nicht 1000).

---

### ATK-10 — Doppelabzug via reference_id-Wiederverwendung 🚫 BLOCKED

**Angriff**: 300 Punkte mit `reference_id: "redemption-777"` ausgeben, dann wiederholen.
**Ergebnis**: BLOCKED — Zweiter Aufruf gibt die ursprüngliche Ausgabe-Transaktion zurück (200). Saldo bleibt 700 (nicht 400).

---

### ATK-11 — SQL-Injection in reference_id 🚫 BLOCKED

**Angriff**: `reference_id: "' OR '1'='1' --"` in einer Earn-Anfrage.
**Ergebnis**: BLOCKED — Parametrisierte Abfragen speichern den Injection-String wörtlich. Saldo ist 100, nicht korrumpiert.

---

### ATK-12 — Float-Betrag 🚫 BLOCKED

**Angriff**: `POST /users/2/points/earn` mit `amount: 10.5`.
**Ergebnis**: BLOCKED — `is_int(10.5)` ist false → null → 422. Saldo unverändert.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|--------|--------|
| ATK-01 | Nicht-authentifizierter Saldo-Zugriff | 🚫 BLOCKED |
| ATK-02 | Benutzerübergreifendes Saldo-Spähen | 🚫 BLOCKED |
| ATK-03 | Selbst-Gewährung an anderen Benutzer | 🚫 BLOCKED |
| ATK-04 | Negativer Betrag beim Verdienen | 🚫 BLOCKED |
| ATK-05 | Null-Betrags-Transaktion | 🚫 BLOCKED |
| ATK-06 | Überziehungsausgabe | 🚫 BLOCKED |
| ATK-07 | Normaler Benutzer passt an | 🚫 BLOCKED |
| ATK-08 | Übermäßiger Earn-Betrag (>MAX) | 🚫 BLOCKED |
| ATK-09 | Doppelgutschrift via reference_id | 🚫 BLOCKED |
| ATK-10 | Doppelabzug via reference_id | 🚫 BLOCKED |
| ATK-11 | SQL-Injection in reference_id | 🚫 BLOCKED |
| ATK-12 | Float-Betrag | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Keine kritischen Befunde. Auth-Kette (401→403), Betragsvalidierung (is_int + >0 + Obergrenze), Überziehungsschutz und reference_id-Idempotenz verhindern alle bekannten Angriffsvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kein `X-User-Id`-Check (Authentifizierung übersprungen) | Nicht-authentifizierter Zugriff auf alle Salden und Transaktionen |
| Benutzerübergreifendes Verdienen ohne Admin-Check | Jeder Benutzer verdient Punkte auf dem Konto eines anderen Benutzers |
| `$amount > 0` ohne `is_int()` | Float `10.5` besteht; Bruchteile von Punkten korrumpieren die Konto-Integrität |
| Kein MAX_EARN-Cap | Angreifer verdient INT_MAX Punkte in einer Anfrage |
| Kein Überziehungscheck vor Ausgabe | Saldo wird negativ; DB CHECK ist letztes Mittel, nicht primärer Schutz |
| Keine `reference_id`-Idempotenz | Netzwerk-Wiederholung verdoppelt Gutschriften oder Abbuchungen |
| Geteilter `reference_id`-Raum für alle Benutzer | `order-1` von Benutzer A blockiert Benutzer B bei Verwendung derselben Referenz |
| `getBalance()` via SUM-Aggregation bei großen Tabellen | Vollständiger Tabellen-Scan pro Anfrage; stattdessen `balance_after` laufende Summe verwenden |
| Admin-Adjust ohne Rollenprüfung zuerst | Nicht-Admin sendet große Anpassung; Rolle vor jeder Geschäftslogik prüfen |
| Bei Duplikat 200 ohne denselben Transaktions-Body zurückgeben | Client kann Idempotenz nicht verifizieren; muss ursprüngliche Transaktion zurückgeben |
