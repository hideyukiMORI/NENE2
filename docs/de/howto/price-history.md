# Anleitung: Produktpreishistorie-API

> **FT-Referenz**: FT67 (`NENE2-FT/pricelog`) — Produktpreishistorie-API
> **ATK**: FT228 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Preishistorie-API, bei der jedes Produkt eine Zeitachse von Preisstufen
(Gültigkeitszeiträume) pflegt. Der aktuelle Preis sowie der Preis zu einem beliebigen Zeitpunkt
können abgefragt werden. Der ATK-Abschnitt dokumentiert zwölf Angriffsvektoren mit Bestanden/Nicht bestanden-Urteilen.

---

## Routen

| Methode | Pfad                              | Beschreibung                                     |
|---------|-----------------------------------|--------------------------------------------------|
| `POST`  | `/products`                       | Produkt erstellen                                |
| `GET`   | `/products`                       | Alle Produkte auflisten                          |
| `GET`   | `/products/{id}`                  | Einzelnes Produkt abrufen                        |
| `POST`  | `/products/{id}/prices`           | Neuen Preis setzen (neue Stufe öffnen)           |
| `GET`   | `/products/{id}/prices`           | Vollständige Preishistorie auflisten             |
| `GET`   | `/products/{id}/prices/current`   | Aktuell gültiger Preis                           |
| `GET`   | `/products/{id}/prices/at`        | Preis zu einem bestimmten Zeitpunkt (`?datetime=`) |

---

## Preisstufen-Modell

Jeder Preis hat einen `effective_from`- und einen `effective_to`-Zeitstempel. Eine Stufe ist „aktiv", wenn:

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` bedeutet, dass die Stufe noch kein Enddatum hat (offenes Intervall).

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- Cent (nicht negativ)
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = offen (aktuell)
    created_at     TEXT    NOT NULL
);
```

---

## Preis setzen: alte Stufe schließen, neue öffnen

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // Alle offenen Stufen schließen, die vor dem neuen effective_from beginnen
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // Neue Stufe öffnen
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

Das UPDATE schließt alle offenen Stufen, deren `effective_from <= newEffectiveFrom`. Damit werden drei Szenarien korrekt behandelt:
- **Neues effective_from in der Zukunft**: Aktuelle Stufe wird am zukünftigen Datum geschlossen.
- **Neues effective_from in der Vergangenheit**: Altes Enddatum wird rückdatiert und eine neue historische Stufe geöffnet.
- **Gleiches effective_from**: Alte Stufe wird zum gleichen Zeitpunkt geschlossen (Nulldauer), neue Stufe beginnt.

> **Hinweis zur Nebenläufigkeit**: UPDATE und INSERT sind nicht in einer Transaktion zusammengefasst. Zwei gleichzeitige `setPrice`-Aufrufe mit demselben `effective_from` können beide die UPDATE-Phase passieren und beide ein INSERT ausführen, was zwei offene Stufen (`effective_to IS NULL`) hinterlässt. Die Abfragen verwenden `ORDER BY effective_from DESC LIMIT 1`, sodass der letzte Insert gewinnt, aber die Historie ist beschädigt. Verwenden Sie `transactional()` für Korrektheit unter Nebenläufigkeit.

---

## Preis zu einem Zeitpunkt abfragen

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

Der Vergleich ist ein lexikografischer Zeichenkettenvergleich von ISO 8601-Datetimes, die als TEXT gespeichert sind. Dies funktioniert **nur dann korrekt, wenn alle Datetimes dasselbe Format und dieselbe Zeitzone verwenden** (z. B. alle UTC `2026-05-27 09:00:00`). Das Mischen von Formaten oder Zeitzonen-Offsets liefert falsche Ergebnisse.

**Beispiel**: Wenn `effective_from` als `"2026-05-27T09:00:00+09:00"` (JST) gespeichert ist und `?datetime=2026-05-27T00:30:00Z` (UTC, derselbe Augenblick), sieht der Zeichenkettenvergleich sie als unterschiedlich an und gibt möglicherweise eine falsche Stufe zurück. Normalisieren Sie alle Datetimes beim Schreiben auf UTC.

---

## Betrag in Cent (Integer)

Geldbeträge werden als Integer (Cent) gespeichert, um Rundungsfehler durch Fließkommazahlen zu vermeiden:

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` lehnt JSON-Floats (`9.99` → PHP float) und Strings ab.
- `$amount < 0` lehnt negative Preise ab.
- `$amount === 0` ist **erlaubt** (kostenlose Produkte / Aktionen).

---

## ATK — Cracker-Angriffstest (FT228)

### ATK-01 — Keine Authentifizierung

**Angriff**: Preis für ein beliebiges Produkt ohne Anmeldedaten setzen.

```http
POST /products/1/prices
{"amount": 1, "currency": "USD", "effective_from": "2026-01-01T00:00:00Z"}
```

**Beobachtet**: `201 Created` — kein Token erforderlich.

**Urteil**: **EXPONIERT** (absichtlich für FT67-Demo). Preis-Mutationen in der Produktion hinter einer Admin-Rolle oder einem API-Key absichern.

---

### ATK-02 — Rückdatierte Preismanipulation

**Angriff**: `effective_from` auf ein vergangenes Datum setzen, um die Preishistorie zu ändern.

```json
{"amount": 0, "currency": "USD", "effective_from": "2020-01-01T00:00:00Z"}
```

**Beobachtet**: `201 Created`. Das UPDATE schließt alle vorhandenen offenen Stufen bei `2020-01-01`, und eine neue Nullpreis-Stufe ab 2020 wird eingefügt. Historische Abfragen (`priceAt`) geben nun den rückdatierten Preis für vergangene Daten zurück.

**Urteil**: **EXPONIERT** — ohne Authentifizierung gibt es keinen Eigentümer, der die Rückdatierung autorisiert. Mit Auth fordern, dass `effective_from >= now()` gilt, sofern der Aufrufer kein Admin ist.

---

### ATK-03 — SQL-Injection über `?datetime=`

**Angriff**: SQL über den `datetime`-Queryparameter einschleusen.

```http
GET /products/1/prices/at?datetime=2026-01-01' OR '1'='1
```

**Beobachtet**: `404 Not Found` — der injizierte String wird als parametrisierter Wert verwendet, sodass der Literalstring mit `effective_from` verglichen wird, was nichts trifft.

**Urteil**: **BLOCKIERT** — PDO-parametrisierte Anweisungen verhindern SQL-Injection.

---

### ATK-04 — Nullbetrag

**Angriff**: Produktpreis auf null setzen (kostenlos).

```json
{"amount": 0, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Beobachtet**: `201 Created`.

**Urteil**: **ABSICHTLICH ERLAUBT** — `amount === 0` ist bewusst zugelassen (Testpläne, Aktionen). Dokumentieren Sie, dass `amount` Cent bedeutet und 0 kostenlos ist. Wenn Nullpreise in Ihrer Domäne ungültig sind, ändern Sie `$amount < 0` zu `$amount <= 0`.

---

### ATK-05 — Negativer Betrag

**Angriff**: Negativen Preis setzen (Rückerstattungsangriff?).

```json
{"amount": -100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Beobachtet**: `422 Unprocessable Entity` — die Prüfung `$amount < 0` gibt false zurück.

**Urteil**: **BLOCKIERT** — negative Beträge werden auf Anwendungsebene abgelehnt.

---

### ATK-06 — Währungscode-Injection (keine Allowlist)

**Angriff**: Preis mit beliebigem oder schädlichem Währungsstring setzen.

```json
{"amount": 100, "currency": "NOTCURRENCY", "effective_from": "2026-05-27T00:00:00Z"}
{"amount": 100, "currency": "<script>alert(1)</script>", "effective_from": "..."}
{"amount": 100, "currency": "'; DROP TABLE price_tiers; --", "effective_from": "..."}
```

**Beobachtet**: Alle geben `201 Created` zurück. Der Währungsstring wird unverändert gespeichert. Der SQL-Injection-String ist sicher (parametrisiert), aber `"NOTCURRENCY"` und die XSS-Nutzlast werden gespeichert.

**Urteil**: **EXPONIERT** — `currency` gegen eine ISO 4217-Allowlist validieren:
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — Extrem großer Betrag

**Angriff**: Betrag einreichen, der größer ist als PHP/SQLite verarbeiten kann.

```json
{"amount": 9999999999999999999, "currency": "USD", "effective_from": "..."}
```

**Beobachtet**: PHP parst große JSON-Integer als Floats, wenn sie `PHP_INT_MAX` (2^63 - 1 auf 64-Bit) überschreiten. `is_int($body['amount'])` gibt false für einen Float zurück → 422.

**Urteil**: **BLOCKIERT** — `is_int()` lehnt JSON-Integer, die zu PHP-Float überlaufen, korrekt ab. Werte innerhalb von `PHP_INT_MAX` werden als SQLite-Integer korrekt gespeichert.

---

### ATK-08 — Ungültiges Datetime in `?datetime=`

**Angriff**: Nicht-Datumsstring an den `priceAt`-Endpunkt übergeben.

```http
GET /products/1/prices/at?datetime=not-a-date
GET /products/1/prices/at?datetime=2026-02-30T00:00:00Z
```

**Beobachtet**: Beide geben `404 Not Found` zurück — die Strings werden lexikografisch mit gespeicherten `effective_from`-Werten verglichen und treffen nichts. Es wird keine Ausnahme geworfen.

**Urteil**: **TEILWEISE EXPONIERT** — der Endpunkt akzeptiert ungültige Datumsangaben stillschweigend und gibt 404 zurück, was Aufrufer verwirren kann, die ein 422 erwarten. Formatvalidierung hinzufügen:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — Zukünftiges effective_from (geplante Preisänderung)

**Angriff**: `effective_from` auf ein zukünftiges Datum setzen, um eine Preisänderung zu planen.

```json
{"amount": 999, "currency": "USD", "effective_from": "2099-12-31T00:00:00Z"}
```

**Beobachtet**: `201 Created`. `currentPrice()` gibt weiterhin den vorherigen Preis zurück (das `effective_from` der zukünftigen Stufe liegt noch in der Zukunft), aber `priceAt("2099-12-31T01:00:00Z")` gibt die neue Stufe zurück.

**Urteil**: **ABSICHTLICH ERLAUBT** — geplante Preisänderungen sind ein legitimer Anwendungsfall. In der API-Spezifikation dokumentieren. Wenn die Planung auf Admins beschränkt sein soll, Auth erfordern und `effective_from <= now + 30 days` für Nicht-Admin-Aufrufer prüfen.

---

### ATK-10 — Gleichzeitiges Preissetzen (Race Condition)

**Angriff**: Zwei gleichzeitige `POST /products/1/prices` mit demselben `effective_from` senden.

**Beobachtet**: Ohne eine Transaktion, die UPDATE + INSERT umschließt, können beide Anfragen die UPDATE-Phase passieren und beide ein INSERT ausführen, was zwei offene Stufen (`effective_to IS NULL`) erzeugt. Abfragen verwenden `ORDER BY effective_from DESC LIMIT 1`, sodass die Ergebnisse nicht deterministisch sind.

**Urteil**: **EXPONIERT** — `setPrice` in `transactional()` einschließen:
```php
return $this->txManager->transactional(function ($tx) use (...) {
    // UPDATE dann INSERT innerhalb der Transaktion
});
```

---

### ATK-11 — Nicht existierende product_id

**Angriff**: Preis für ein nicht existierendes Produkt setzen.

```http
POST /products/99999/prices
{"amount": 100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Beobachtet**: `404 Not Found` — `findProduct(99999)` gibt `null` zurück und der Controller gibt eine Not-Found-Problem-Details-Antwort zurück, bevor `setPrice` aufgerufen wird.

**Urteil**: **BLOCKIERT** — Existenzprüfung vor der Mutation.

---

### ATK-12 — Nicht-numerische Pfad-IDs

**Angriff**: Nicht-Ziffer-Strings als `{id}` übergeben.

```http
GET /products/abc
GET /products/-1
POST /products/0/prices
```

**Beobachtet**: Alle geben `404 Not Found` zurück. `(int) "abc"` = `0`; `findProduct(0)` gibt `null` zurück (kein Produkt mit ID 0); Controller gibt 404 zurück.

**Urteil**: **BLOCKIERT** in der Praxis. Hinweis: `(int) "9abc"` = `9` — ein Produkt mit ID 9 würde übereinstimmen. Verwenden Sie `ctype_digit()` für strikte Pfadvalidierung, wenn nötig.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| ATK-01 | Keine Authentifizierung | EXPONIERT (absichtlich) |
| ATK-02 | Rückdatierte Preismanipulation | EXPONIERT |
| ATK-03 | SQL-Injection über `?datetime=` | BLOCKIERT |
| ATK-04 | Nullbetrag | ABSICHTLICH ERLAUBT |
| ATK-05 | Negativer Betrag | BLOCKIERT |
| ATK-06 | Währungscode-Injection (keine Allowlist) | EXPONIERT |
| ATK-07 | Extrem großer Betrag | BLOCKIERT |
| ATK-08 | Ungültiges Datetime-Format | TEILWEISE EXPONIERT |
| ATK-09 | Zukünftiges `effective_from` (geplanter Preis) | ABSICHTLICH ERLAUBT |
| ATK-10 | Gleichzeitiges setPrice (Race Condition) | EXPONIERT |
| ATK-11 | Nicht existierendes Produkt | BLOCKIERT |
| ATK-12 | Nicht-numerische Pfad-IDs | BLOCKIERT |

**Echte Schwachstellen, die vor dem Produktivbetrieb behoben werden müssen**:
1. **ATK-01** — Authentifizierung/Autorisierung hinzufügen
2. **ATK-02** — Rückdatierung auf Admin-Aufrufer beschränken (oder vollständig verbieten)
3. **ATK-06** — `currency` gegen ISO 4217-Allowlist validieren
4. **ATK-08** — `?datetime=`-Format vor der DB-Abfrage validieren
5. **ATK-10** — `setPrice` UPDATE+INSERT in eine Transaktion einschließen

---

## Verwandte Anleitungen

- [`expense-tracker.md`](expense-tracker.md) — `is_int()`-Betragsvalidierung und ISO 8601-Datum-Roundtrip
- [`habit-tracker.md`](habit-tracker.md) — ATK-01〜12-Muster (vorheriger ATK-Zyklus)
- [`prevent-double-booking.md`](prevent-double-booking.md) — Transaktionales Read-Check-Write
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — Strikte ISO 8601-Validierung
