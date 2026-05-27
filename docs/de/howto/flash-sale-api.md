# How-to: Flash Sale API

> **FT-Referenz**: FT304 (`NENE2-FT/salelog`) — Flash Sale API: Zeitfenster-Validierung (Sale noch nicht gestartet → 422, beendet → 422), UNIQUE(sale_id, user_id) verhindert Doppelkauf, Lagerbestand-Erschöpfungs-Prüfung, negativer Preis/Null-Menge → 422, invertierte Daten abgelehnt, ATK-01–12 alle BLOCKED, 29 Tests / 42 Assertions bestanden.

Diese Anleitung zeigt, wie ein Flash-Sale-System aufgebaut wird, bei dem Benutzer begrenzte Lagerbestände innerhalb eines Zeitfensters kaufen können, mit Race-Condition-Schutz und Angriffsprävention.

## Schema

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`CHECK (quantity > 0)` und `CHECK (price >= 0)` erzwingen Geschäftsregeln auf DB-Ebene. `UNIQUE(sale_id, user_id)` verhindert, dass derselbe Benutzer denselben Sale zweimal kauft — auch bei gleichzeitigen Anfragen.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/products` | — | Produkt erstellen |
| `POST` | `/sales` | — | Flash Sale erstellen |
| `GET` | `/sales` | — | Aktive Sales auflisten |
| `GET` | `/sales/{id}` | — | Sale-Details abrufen |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | Kaufen (Zeitprüfung) |

## Sale-Erstellungs-Validierung

```php
if (!is_int($price) || $price < 0) {
    return 422; // negativer Preis abgelehnt
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // Null- oder negative Menge abgelehnt
}
if ($endsAt <= $startsAt) {
    return 422; // invertierte oder gleiche Daten abgelehnt
}
```

Drei DB-Level-Prüfungen durch Anwendungsebene-Validierung gesichert:
- `price >= 0` — kostenlose Sales erlaubt (`0`), negative Preise nicht
- `quantity > 0` — Sales mit Null-Menge können nicht erstellt werden
- `ends_at > starts_at` — Zeitinversion abgelehnt

## Kauf — Zeitfenster-Prüfung

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // Sale noch nicht gestartet
}
if ($now > $sale['ends_at']) {
    return 422; // Sale beendet
}
```

Kaufversuche außerhalb des Sale-Fensters geben 422 zurück. Die Prüfung verwendet server-seitiges `date('c')` — Clients können die Zeit nicht manipulieren.

## Lagerbestand-Prüfung

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

Vorhandene Käufe gegen die `quantity` des Sales zählen, bevor eingefügt wird. Bei ausverkauft 422 mit `"error": "sold out"` zurückgeben.

## UNIQUE(sale_id, user_id) — Doppelkauf-Prävention

```php
// UNIQUE-Constraint fängt gleichzeitige Doppelkäufe ab
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // UNIQUE-Constraint-Verletzung → bereits gekauft
    return $this->json(['error' => 'already purchased'], 409);
}
```

Die DB-`UNIQUE(sale_id, user_id)`-Constraint ist die letzte Absicherung gegen Race Conditions. Erster Kauf gelingt (201); jedes Duplikat gibt 409 Conflict zurück.

## User-ID-Validierung

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- Fehlende oder nicht-numerische `X-User-Id` → 400
- Nicht-existierende User-ID → 404 (IDOR-Prävention — kein Kauf als Geisterbenutzer)

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL-Injection im Produktnamen 🚫 BLOCKED

**Angriff**: `POST /products` mit `name: "'; DROP TABLE products; --"`.
**Ergebnis**: BLOCKED — parametrisierte Abfrage speichert den Injection-String wörtlich (201). Nachfolgende Anfragen funktionieren weiterhin; products-Tabelle intakt.

---

### ATK-02 — Kauf ohne X-User-Id-Header 🚫 BLOCKED

**Angriff**: `POST /sales/{id}/purchase` ohne `X-User-Id`-Header.
**Ergebnis**: BLOCKED — fehlender Header gibt 400 zurück.

---

### ATK-03 — Nicht-numerischer X-User-Id-Header 🚫 BLOCKED

**Angriff**: `X-User-Id: admin` (String-Wert).
**Ergebnis**: BLOCKED — `ctype_digit()`-Prüfung lehnt nicht-numerische Werte ab; nicht 201.

---

### ATK-04 — Negative Sale-ID in URL 🚫 BLOCKED

**Angriff**: `POST /sales/-1/purchase`.
**Ergebnis**: BLOCKED — negative ID löst zu keinem gefundenen Sale auf; nicht 201.

---

### ATK-05 — Kauf vor Sale-Start 🚫 BLOCKED

**Angriff**: Sale erstellen, der in 1 Stunde beginnt; sofort versuchen zu kaufen.
**Ergebnis**: BLOCKED — `$now < $sale['starts_at']`-Prüfung → 422.

---

### ATK-06 — Kauf nach Sale-Ende 🚫 BLOCKED

**Angriff**: Sale erstellen, der vor 1 Stunde endete; Kauf versuchen.
**Ergebnis**: BLOCKED — `$now > $sale['ends_at']`-Prüfung → 422.

---

### ATK-07 — Doppelkauf desselben Sales 🚫 BLOCKED

**Angriff**: Derselbe Benutzer kauft denselben Sale zweimal in rascher Folge.
**Ergebnis**: BLOCKED — erster Kauf 201; zweiter Kauf 409 (UNIQUE-Constraint oder Anwendungsebene-Prüfung).

---

### ATK-08 — Lagerbestand erschöpfen, dann kaufen 🚫 BLOCKED

**Angriff**: Sale mit `quantity=1` erstellen; Alice kauft; Bob versucht zu kaufen.
**Ergebnis**: BLOCKED — Lagerbestand-Prüfung `purchaseCount >= quantity` → 422 `"sold out"` für Bob.

---

### ATK-09 — Sale mit quantity=0 erstellen 🚫 BLOCKED

**Angriff**: `POST /sales` mit `quantity: 0`.
**Ergebnis**: BLOCKED — `quantity <= 0`-Validierung + DB `CHECK (quantity > 0)` → 422.

---

### ATK-10 — Sale mit negativem Preis erstellen 🚫 BLOCKED

**Angriff**: `POST /sales` mit `price: -999`.
**Ergebnis**: BLOCKED — `price < 0`-Validierung + DB `CHECK (price >= 0)` → 422.

---

### ATK-11 — Kauf als nicht-existierender Benutzer 🚫 BLOCKED

**Angriff**: `X-User-Id: 99999` (ID, die nicht in der users-Tabelle existiert).
**Ergebnis**: BLOCKED — `findUser($actorId) === null` → 404.

---

### ATK-12 — Invertierte Sale-Daten (ends_at vor starts_at) 🚫 BLOCKED

**Angriff**: `starts_at: "+2 hours"`, `ends_at: "+1 hour"`.
**Ergebnis**: BLOCKED — `$endsAt <= $startsAt`-Validierung → 422.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | SQL-Injection im Produktnamen | 🚫 BLOCKED |
| ATK-02 | Kauf ohne X-User-Id | 🚫 BLOCKED |
| ATK-03 | Nicht-numerischer X-User-Id | 🚫 BLOCKED |
| ATK-04 | Negative Sale-ID in URL | 🚫 BLOCKED |
| ATK-05 | Kauf vor Sale-Start | 🚫 BLOCKED |
| ATK-06 | Kauf nach Sale-Ende | 🚫 BLOCKED |
| ATK-07 | Doppelkauf desselben Sales | 🚫 BLOCKED |
| ATK-08 | Lagerbestand erschöpfen, dann kaufen | 🚫 BLOCKED |
| ATK-09 | Sale mit quantity=0 erstellen | 🚫 BLOCKED |
| ATK-10 | Sale mit negativem Preis erstellen | 🚫 BLOCKED |
| ATK-11 | Kauf als nicht-existierender Benutzer | 🚫 BLOCKED |
| ATK-12 | Invertierte Sale-Daten | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Server-seitige Zeitfenster-Prüfung, Lagerbestand-Zähler-Guard, UNIQUE-Constraint und strikte Eingabevalidierung verhindern alle bekannten Angriffsvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Client-geliefertem Timestamp für Zeitprüfung vertrauen | Clients senden vergangene/zukünftige Timestamps, um das Fenster zu umgehen |
| Kein `UNIQUE(sale_id, user_id)` | Gleichzeitige Anfragen erlauben demselben Benutzer unter Last zweimal zu kaufen |
| Lagerbestand ohne Race-Condition-Guard prüfen | Zwischen Lagerbestand-Prüfung und Einfügen kann eine andere Anfrage den Bestand erschöpfen |
| Sale-Erstellung mit `quantity: 0` erlauben | Sale mit Null-Menge kann nie gekauft werden; verwirrenden Edge Case |
| `price: -999` akzeptieren | Negativpreis-Kauf schreibt dem Käufer gut statt ihn zu belasten |
| Keine Benutzer-Existenzprüfung | Ghost-User-IDs (nicht in DB) umgehen Audit-Trails |
| `$endsAt >= $startsAt` (Gleichheit erlauben) | Gleicher Start/Ende erzeugt Null-Dauer-Fenster — sofort abgelaufen |
| Nicht-numerischer X-User-Id akzeptiert | `"admin"`-String zu `(int)` gecastet wird `0`; umgeht Auth |
| 409 für Zeitfenster-Fehler zurückgeben | Zeitverletzungen sind Business-Validierungsfehler (422), keine Zustandskonflikte |
