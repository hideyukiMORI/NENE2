# How-to: Idempotency-Key-Muster

> **FT-Referenz**: FT276 (`NENE2-FT/csrflog`) — Idempotency-Key-Header für zustandsändernde Anfragen: UNIQUE-DB-Constraint, Replay gibt ursprüngliches Ergebnis zurück (200), Body-Änderungen beim Replay werden ignoriert, Race Condition durch DatabaseConstraintException behandelt, 15 Tests / 30 Assertions PASS.
>
> **ATK-Assessment**: ATK-01 bis ATK-12 am Ende dieses Dokuments enthalten.

Doppelte Bestellungen oder Ressourcenerstellungen durch Netzwerk-Wiederholungsversuche verhindern, indem Clients bei jeder zustandsändernden Anfrage einen `Idempotency-Key`-Header mitschicken müssen.

## Warum es wichtig ist

Wenn ein Client `POST /orders` sendet und das Netzwerk abbricht, bevor er eine Antwort erhält, wird er erneut versuchen. Ohne Idempotenz erstellt dieser Wiederholungsversuch eine zweite Bestellung. Mit einem `Idempotency-Key` kann der Server den Wiederholungsversuch erkennen und stattdessen das ursprüngliche Ergebnis zurückgeben.

Stripe, GitHub und viele andere Produktions-APIs verwenden genau dieses Muster.

## Datenbankschema

Einen `UNIQUE`-Constraint auf die Idempotency-Key-Spalte hinzufügen. Dieser einzelne Constraint behandelt die unten beschriebene Race Condition.

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## Handler-Implementierung

```php
// 1. Header lesen und validieren
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. Auf vorhandenen Eintrag prüfen (Replay-Pfad)
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // Replay — Original mit 200 zurückgeben
}

// 3. Request-Body validieren
$body = json_decode((string) $request->getBody(), true);
// ... Felder validieren ...

// 4. Erstellen — UNIQUE-Constraint behandelt die Race Condition
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // Eine andere Anfrage mit demselben Key hat das Rennen gewonnen — deren Ergebnis zurückgeben
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## Repository

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // Wirft DatabaseConstraintException bei UNIQUE-Verletzung (Race Condition)
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## Wichtige Designentscheidungen

### Replay gibt 200 zurück, nicht 201

Die zweite Anfrage ist ein Replay, keine Erstellung. `200 OK` teilt dem Client mit: "Sie haben das schon gesehen", ohne Verwirrung darüber zu erzeugen, was erstellt wurde.

### Replay ignoriert den Body

Wenn der Client denselben `Idempotency-Key` mit einem anderen Body sendet, wird das **ursprüngliche** Ergebnis zurückgegeben. Der Server behandelt einen übereinstimmenden Key als Beweis dafür, dass die Anfrage bereits verarbeitet wurde, unabhängig davon, was der Body sagt.

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← ursprüngliche Bestellung, Body ignoriert
```

Das ist beabsichtigt. Wenn der Client eine echte andere Ressource erstellen möchte, muss er einen neuen Key verwenden.

### UNIQUE-Constraint als Race-Condition-Schutz

Zwei gleichzeitige Anfragen mit demselben Key konkurrieren. Der `UNIQUE`-Constraint der DB stellt sicher, dass nur ein INSERT erfolgreich ist. Der Verlierer fängt `DatabaseConstraintException` ab und ruft die Zeile des Gewinners ab.

## Was Clients als Key verwenden sollten

UUID v4 ist die gebräuchlichste Wahl. Der Client generiert den Key vor dem Senden der Anfrage und speichert ihn lokal, damit er bei Bedarf mit demselben Key erneut versuchen kann.

```js
// Client-seitig (JavaScript)
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## Header lesen

PSR-7-Header-Namen sind case-insensitive. `getHeaderLine('Idempotency-Key')`, `getHeaderLine('idempotency-key')` und `getHeaderLine('IDEMPOTENCY-KEY')` geben alle denselben Wert zurück. NENE2 verwendet Nyholm/PSR-7, das dies korrekt implementiert.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Idempotency-Key weglassen, um Duplikatprüfung zu umgehen 🚫 BLOCKED

**Angriff**: `POST /orders` ohne den `Idempotency-Key`-Header senden.
**Ergebnis**: BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → 422 mit `missing-idempotency-key` Problem Detail. Es wird keine Bestellung erstellt.

---

### ATK-02 — Leeren Idempotency-Key senden 🚫 BLOCKED

**Angriff**: `Idempotency-Key: ` (nur Leerzeichen) senden.
**Ergebnis**: BLOCKED — `trim()` reduziert nur-Leerzeichen-Strings auf `''` → gleiche 422 wie ATK-01.

---

### ATK-03 — Replay mit geändertem Body, um Bestellungsinhalt zu ändern 🚫 BLOCKED

**Angriff**: `POST /orders` mit Key `uuid-abc` und `{quantity: 1}` senden. Beim Replay denselben Key mit `{quantity: 99}` verwenden.
**Ergebnis**: BLOCKED — der Server findet die vorhandene Zeile über `idempotency_key` und gibt sie sofort zurück, bevor der Body gelesen wird. Der neue Body wird nie verarbeitet.

---

### ATK-04 — Zwei Bestellungen mit unterschiedlichen Keys erstellen ✅ SAFE (beabsichtigt)

**Angriff**: Zwei verschiedene `Idempotency-Key`-Werte verwenden, um legitim zwei Bestellungen zu erstellen.
**Ergebnis**: SAFE (by design) — verschiedene Keys sind verschiedene Anfragen. Beide Bestellungen werden erstellt. Dies ist das beabsichtigte Verhalten: Idempotenz gilt pro Key, nicht pro Body.

---

### ATK-05 — Race Condition: zwei gleichzeitige Anfragen mit demselben Key 🚫 BLOCKED

**Angriff**: Zwei identische Anfragen gleichzeitig senden, bevor eine davon abgeschlossen ist.
**Ergebnis**: BLOCKED — beide Anfragen passieren die `findByIdempotencyKey`-Prüfung (noch keine vorhandene Zeile), aber nur ein INSERT gelingt. Der Verlierer fängt `DatabaseConstraintException` ab, ruft die Zeile des Gewinners ab und gibt sie mit 200 zurück. Der UNIQUE-Constraint ist der Race-Schutz.

---

### ATK-06 — Negative Mengeninjektion 🚫 BLOCKED

**Angriff**: `{item: "widget", quantity: -1, price: 9.99}` mit einem gültigen Key senden.
**Ergebnis**: BLOCKED — `if ($quantity <= 0)` → 422 Validierungsfehler. Es wird keine Bestellung erstellt.

---

### ATK-07 — Null-Mengeninjektion 🚫 BLOCKED

**Angriff**: `{item: "widget", quantity: 0, price: 9.99}` senden.
**Ergebnis**: BLOCKED — gleiche `quantity <= 0`-Prüfung → 422.

---

### ATK-08 — Fehlende Pflichtfelder im Body 🚫 BLOCKED

**Angriff**: `{quantity: 1}` ohne `item`-Feld senden.
**Ergebnis**: BLOCKED — `if ($item === '')` → 422 Validierungsfehler.

---

### ATK-09 — CSRF über ursprungsübergreifende Browser-Anfrage 🚫 BLOCKED (Design)

**Angriff**: Bösartige Website macht eine ursprungsübergreifende `POST /orders`-Anfrage aus einem Browser.
**Ergebnis**: BLOCKED (by design) — JSON-APIs erfordern `Content-Type: application/json`. Browser-CSRF-Angriffe können über `<form>` nur formular-kodierte oder Nur-Text-Bodies ohne Preflight senden. Ein JSON-Body löst einen CORS-Preflight aus; die CORS-Richtlinie des Servers entscheidet, ob ursprungsübergreifende Schreibvorgänge erlaubt sind. Zusätzlich bietet die Anforderung von `Idempotency-Key` sekundären Schutz, da gefälschte Anfragen keinen eindeutigen Key vorhersagen können.

---

### ATK-10 — Negativer Preis-Injection 🚫 BLOCKED

**Angriff**: `{item: "widget", quantity: 1, price: -100.0}` senden.
**Ergebnis**: BLOCKED — `if ($price < 0)` → 422 Validierungsfehler.

---

### ATK-11 — Float/String-Mengentypkoercion 🚫 BLOCKED

**Angriff**: `{quantity: "1"}` oder `{quantity: 1.5}` (String oder Float) senden.
**Ergebnis**: BLOCKED — `is_int($body['quantity'])` lehnt Strings und Floats ab; `1.5` ist Float → 422.

---

### ATK-12 — SQL-Injection über Idempotency-Key 🚫 BLOCKED

**Angriff**: `Idempotency-Key: '; DROP TABLE orders; --` senden.
**Ergebnis**: BLOCKED — der Key wird nur in parametrisierten Abfragen verwendet (`WHERE idempotency_key = ?`). SQL-Injection über Header-Wert ist nicht möglich.

---

### ATK Summary

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Fehlender Idempotency-Key | 🚫 BLOCKED |
| ATK-02 | Leerer/nur-Leerzeichen-Key | 🚫 BLOCKED |
| ATK-03 | Replay mit geändertem Body | 🚫 BLOCKED |
| ATK-04 | Verschiedene Keys = verschiedene Bestellungen | ✅ SAFE (beabsichtigt) |
| ATK-05 | Race Condition bei gleichem Key | 🚫 BLOCKED |
| ATK-06 | Negative Menge | 🚫 BLOCKED |
| ATK-07 | Null-Menge | 🚫 BLOCKED |
| ATK-08 | Fehlende Body-Felder | 🚫 BLOCKED |
| ATK-09 | CSRF über ursprungsübergreifenden POST | 🚫 BLOCKED |
| ATK-10 | Negativer Preis | 🚫 BLOCKED |
| ATK-11 | Float/String-Mengentypkoercion | 🚫 BLOCKED |
| ATK-12 | SQL-Injection über Key-Header | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
Das Idempotency-Key-Muster, parametrisierte Abfragen und strenge `is_int()`-Validierung verhindern alle getesteten Angriffsvektoren.
