# How-to: Produkt-Preishistorie-API

> **FT-Referenz**: FT67 (`NENE2-FT/pricelog`) — Produkt-Preishistorie-API
> **ATK**: FT228 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Preishistorie-API, bei der jedes Produkt eine Zeitleiste von Preisstufen
(Geltungszeiträume) führt. Der aktuelle Preis und der Preis zu einem beliebigen Zeitpunkt können
abgefragt werden. Der ATK-Abschnitt dokumentiert zwölf Angriffsvektoren mit Bestanden/Nicht-bestanden-Urteilen.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/products` | Produkt erstellen |
| `GET`  | `/products` | Alle Produkte auflisten |
| `GET`  | `/products/{id}` | Einzelnes Produkt abrufen |
| `POST` | `/products/{id}/prices` | Neuen Preis setzen (neue Stufe öffnen) |
| `GET` | `/products/{id}/prices` | Vollständige Preishistorie auflisten |
| `GET` | `/products/{id}/prices/current` | Aktuell aktiver Preis |
| `GET` | `/products/{id}/prices/at` | Preis zu einem bestimmten Zeitpunkt (`?datetime=`) |

---

## Preisstufenmodell

Jeder Preis hat einen `effective_from`- und `effective_to`-Zeitstempel. Eine Stufe ist „aktiv" wenn:

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` bedeutet, dass die Stufe noch kein Enddatum hat (offenes Intervall).

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- Cent (nicht-negativ)
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
    // Jede offene Stufe schließen, die vor dem neuen effective_from beginnt
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

Das UPDATE schließt jede offene Stufe, deren `effective_from <= newEffectiveFrom`. Dies behandelt
korrekt drei Szenarien:
- **Neues effective_from in der Zukunft**: schließt die aktuelle Stufe zum zukünftigen Datum.
- **Neues effective_from in der Vergangenheit**: rückdatiert den Abschluss der alten Stufe und öffnet eine neue historische Stufe.
- **Doppeltes effective_from**: schließt die alte Stufe im selben Augenblick, in dem sie begann (Nulldauer), dann öffnet die neue.

> **Nebenläufigkeits-Vorbehalt**: UPDATE und INSERT sind nicht in eine Transaktion eingewickelt. Zwei
> gleichzeitige `setPrice`-Aufrufe mit demselben `effective_from` können beide die UPDATE-Phase bestehen
> und beide INSERT, was zwei offene Stufen (`effective_to IS NULL`) hinterlässt. Die Abfragen verwenden
> `ORDER BY effective_from DESC LIMIT 1`, sodass der letzte INSERT gewinnt, aber die Historie korrumpiert ist.
> In `transactional()` für Korrektheit unter Nebenläufigkeit einwickeln.

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

Der Vergleich ist lexikografischer String-Vergleich auf ISO-8601-Datetimes, die als TEXT gespeichert sind.
Dies funktioniert korrekt **nur wenn alle Datetimes dasselbe Format und dieselbe Zeitzone verwenden** (z.B.
alle UTC `2026-05-27 09:00:00`). Das Mischen von Formaten oder Zeitzonen-Offsets erzeugt falsche Ergebnisse.

**Beispiel**: Wenn `effective_from` als `"2026-05-27T09:00:00+09:00"` (JST) gespeichert ist und
`?datetime=2026-05-27T00:30:00Z` (UTC, gleicher Augenblick), sieht der String-Vergleich sie
als verschieden und kann eine falsche Stufe zurückgeben. Alle Datetimes beim Schreiben auf UTC normalisieren.

---

## Betrag in Cent (Integer)

Geldwerte werden als Integer (Cent) gespeichert, um Gleitkomma-Rundung zu vermeiden:

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` lehnt JSON-Floats (`9.99` → PHP-Float) und Strings ab.
- `$amount < 0` lehnt negative Preise ab.
- `$amount === 0` ist **erlaubt** (kostenlose Produkte / Aktionen).

---

## ATK — Cracker-Angriffstest (FT228)

### ATK-01 — Keine Authentifizierung

**Angriff**: Preis für ein beliebiges Produkt ohne Anmeldedaten setzen.

**Beobachtet**: `201 Created` — kein Token erforderlich.

**Urteil**: **EXPOSED** (by Design für FT67-Demo).
Preismutationen hinter Admin-Rolle oder API-Key sperren in Produktion.

---

### ATK-02 — Rückdatierte Preismanipulation

**Angriff**: `effective_from` auf ein vergangenes Datum setzen, um die Preishistorie zu ändern.

**Beobachtet**: `201 Created`. Das UPDATE schließt jede vorhandene offene Stufe bei `2020-01-01`,
und eine neue Null-Preis-Stufe ab 2020 wird eingefügt. Historische Abfragen (`priceAt`) geben jetzt
den rückdatierten Preis für vergangene Daten zurück.

**Urteil**: **EXPOSED** — ohne Authentifizierung gibt es keinen Eigentümer, der Rückdatierung autorisiert.
Mit Auth fordern, dass `effective_from >= now()` es sei denn der Aufrufer ist Admin.

---

### ATK-03 — SQL-Injection via `?datetime=`

**Angriff**: SQL über den `datetime`-Query-Parameter injizieren.

**Beobachtet**: `404 Not Found` — der injizierte String wird als parametrisierter Wert verwendet,
sodass der Literal-String gegen `effective_from` verglichen wird, was nichts trifft.

**Urteil**: **BLOCKED** — PDO-parametrisierte Statements verhindern SQL-Injection.

---

### ATK-04 — Null-Betrag-Preis

**Angriff**: Produktpreis auf null setzen (kostenlos).

**Beobachtet**: `201 Created`.

**Urteil**: **BY DESIGN AKZEPTIERT** — `amount === 0` ist absichtlich erlaubt
(Testpläne, Aktionen). Dokumentieren, dass `amount` Cent bedeutet und 0 kostenlos bedeutet.
Wenn Null-Preis für die Domain nicht gültig ist, `$amount < 0` zu `$amount <= 0` ändern.

---

### ATK-05 — Negativer Betrag

**Angriff**: Negativen Preis setzen (Rückerstattungsangriff?).

**Beobachtet**: `422 Unprocessable Entity` — die Prüfung `$amount < 0` gibt false zurück.

**Urteil**: **BLOCKED** — negative Beträge auf Anwendungsebene abgelehnt.

---

### ATK-06 — Währungscode-Injection (keine Allowlist)

**Angriff**: Preis mit beliebigem oder bösartigem Währungsstring setzen.

**Beobachtet**: Alle geben `201 Created` zurück. Der Währungsstring wird wörtlich gespeichert.
Der SQL-Injection-String ist sicher (parametrisiert), aber `"NOTCURRENCY"` und der XSS-Payload werden gespeichert.

**Urteil**: **EXPOSED** — `currency` gegen eine ISO-4217-Allowlist validieren:
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — Extrem großer Betrag

**Angriff**: Betrag einreichen, der größer ist als PHP/SQLite verarbeiten kann.

**Beobachtet**: PHP parst große JSON-Integer als Floats, wenn sie `PHP_INT_MAX` überschreiten.
`is_int($body['amount'])` gibt false für einen Float zurück → 422.

**Urteil**: **BLOCKED** — `is_int()` lehnt JSON-Integer korrekt ab, die zu PHP-Float überlaufen.

---

### ATK-08 — Ungültiges Datetime-Format in `?datetime=`

**Angriff**: Einen Nicht-Datum-String an den `priceAt`-Endpunkt übergeben.

**Beobachtet**: Beide geben `404 Not Found` zurück — die Strings werden lexikografisch gegen gespeicherte
`effective_from`-Werte verglichen und treffen nichts.

**Urteil**: **TEILWEISE EXPOSED** — der Endpunkt akzeptiert still ungültige Daten und gibt 404 zurück.
Formatvalidierung hinzufügen:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — Zukünftiges effective_from (geplanter Preis)

**Angriff**: `effective_from` auf ein zukünftiges Datum setzen, um eine Preisänderung zu planen.

**Beobachtet**: `201 Created`. `currentPrice()` gibt noch den vorherigen Preis zurück, aber `priceAt(...)` gibt die neue Stufe zurück.

**Urteil**: **BY DESIGN AKZEPTIERT** — geplante Preisgestaltung ist ein legitimer Anwendungsfall.

---

### ATK-10 — Gleichzeitiges Preissetzen (Race Condition)

**Angriff**: Zwei simultane `POST /products/1/prices` mit demselben `effective_from` senden.

**Beobachtet**: Ohne eine Transaktion, die UPDATE + INSERT einwickelt, können beide Anfragen die UPDATE-Phase bestehen und beide INSERT.

**Urteil**: **EXPOSED** — `setPrice` in `transactional()` einwickeln.

---

### ATK-11 — Nicht existierende product_id

**Angriff**: Preis für ein nicht existierendes Produkt setzen.

**Beobachtet**: `404 Not Found` — `findProduct(99999)` gibt `null` zurück.

**Urteil**: **BLOCKED** — Existenzprüfung vor Mutation.

---

### ATK-12 — Nicht-numerische Pfad-IDs

**Angriff**: Nicht-Ziffern-Strings als `{id}` übergeben.

**Beobachtet**: Alle geben `404 Not Found` zurück.

**Urteil**: **BLOCKED** in der Praxis. Hinweis: `(int) "9abc"` = `9` — ein Produkt mit
ID 9 würde übereinstimmen. `ctype_digit()` für strikte Pfadvalidierung verwenden.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|---------|
| ATK-01 | Keine Authentifizierung | EXPOSED (by Design) |
| ATK-02 | Rückdatierte Preismanipulation | EXPOSED |
| ATK-03 | SQL-Injection via `?datetime=` | BLOCKED |
| ATK-04 | Null-Betrag-Preis | BY DESIGN AKZEPTIERT |
| ATK-05 | Negativer Betrag | BLOCKED |
| ATK-06 | Währungscode-Injection (keine Allowlist) | EXPOSED |
| ATK-07 | Extrem großer Betrag | BLOCKED |
| ATK-08 | Ungültiges Datetime-Format | TEILWEISE EXPOSED |
| ATK-09 | Zukünftiges `effective_from` (geplanter Preis) | BY DESIGN AKZEPTIERT |
| ATK-10 | Gleichzeitiges setPrice Race Condition | EXPOSED |
| ATK-11 | Nicht existierendes Produkt | BLOCKED |
| ATK-12 | Nicht-numerische Pfad-IDs | BLOCKED |

**Echte Schwachstellen vor Produktionseinsatz zu beheben**:
1. **ATK-01** — Authentifizierung/Autorisierung hinzufügen
2. **ATK-02** — Rückdatierung auf Admin-Aufrufer beschränken (oder ganz verbieten)
3. **ATK-06** — `currency` gegen ISO-4217-Allowlist validieren
4. **ATK-08** — `?datetime=`-Format vor DB-Abfrage validieren
5. **ATK-10** — `setPrice`-UPDATE+INSERT in Transaktion einwickeln

---

## Verwandte Anleitungen

- [`expense-tracker.md`](expense-tracker.md) — `is_int()`-Betragsvalidierung und ISO-8601-Datum-Roundtrip
- [`habit-tracker.md`](habit-tracker.md) — ATK-01~12-Muster (vorheriger ATK-Zyklus)
- [`prevent-double-booking.md`](prevent-double-booking.md) — transaktionales Lesen-Prüfen-Schreiben
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — strikte ISO-8601-Validierung
