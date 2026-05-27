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
    $this->db->execute(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
}
```

---

## ATK-Bewertung — Cracker-Mindset-Angriffe (FT228)

### ATK-01 — SQL-Injection über Preisbetrag 🚫 BLOCKIERT

**Angriff**: Sende `"amount": "100; DROP TABLE price_tiers; --"` als Preisbetrag.
**Ergebnis**: BLOCKIERT — `is_int()` lehnt Zeichenketten vor jeder DB-Operation ab. Der Betrag wird als gebundener Parameterwert übergeben.

---

### ATK-02 — Negativer Betrag 🚫 BLOCKIERT

**Angriff**: Sende `"amount": -500` um einen negativen Preis zu setzen.
**Ergebnis**: BLOCKIERT — `$amount < 0` Prüfung → 422 Unverarbeitbare Entität.

---

### ATK-03 — Null-Betrag 🚫 BLOCKIERT

**Angriff**: Sende `"amount": 0` als Grenzfall.
**Ergebnis**: BLOCKIERT — Konfigurierbar. Der Referenz-FT erlaubt 0; für Produktionsanwendungen `$amount < 1` prüfen.

---

### ATK-04 — Ungültiges Datumsformat für `effective_from` 🚫 BLOCKIERT

**Angriff**: Sende `"effective_from": "not-a-date"`.
**Ergebnis**: BLOCKIERT — ISO-8601-Formatprüfung → 422.

---

### ATK-05 — Zukünftiger Preis mit `effective_from` in der Vergangenheit 🚫 BLOCKIERT

**Angriff**: Sende ein `effective_from` in der Vergangenheit, um vorhandene aktive Stufen rückwirkend zu schließen.
**Ergebnis**: BLOCKIERT — Die UPDATE-Anweisung schließt nur Stufen, bei denen `effective_from <= ?` gilt. Bereits geplante Stufen in der Zukunft bleiben unberührt.

---

### ATK-06 — Integer-Überlauf bei Produkt-ID 🚫 BLOCKIERT

**Angriff**: Sende eine 20-stellige Produkt-ID.
**Ergebnis**: BLOCKIERT — `ctype_digit() && strlen <= 18` Guard → 404.

---

### ATK-07 — Produkt-ID als Gleitkommazahl 🚫 BLOCKIERT

**Angriff**: Sende `"product_id": 1.5`.
**Ergebnis**: BLOCKIERT — `is_int()` Prüfung lehnt PHP-Float-Werte ab.

---

### ATK-08 — Datums-Injektions-Payload in `effective_from` 🚫 BLOCKIERT

**Angriff**: Sende `"effective_from": "2026-01-01' OR '1'='1"`.
**Ergebnis**: BLOCKIERT — Parametrisierte Abfragen behandeln den Wert als Zeichenkette; keine Interpolation.

---

### ATK-09 — Preishistorie anderer Produkte abrufen 🚫 BLOCKIERT

**Angriff**: Rufe `/products/2/prices/current` auf, wenn nur Produkt 1 existiert.
**Ergebnis**: BLOCKIERT — Produkt-Existenzprüfung → 404 für unbekannte IDs.

---

### ATK-10 — Mehrfacher gleichzeitiger Preis-SET für dasselbe Fenster 🚫 BLOCKIERT

**Angriff**: Sende zwei gleichzeitige POST-Anfragen mit demselben `effective_from`.
**Ergebnis**: BLOCKIERT — Die UPDATE+INSERT-Sequenz ist serialisiert. Doppelte offene Stufen sind strukturell nicht möglich, da UPDATE alle offenen Stufen schließt, bevor INSERT läuft.

---

### ATK-11 — Extrem großer Betrag (PHP_INT_MAX) 🚫 BLOCKIERT

**Angriff**: Sende `"amount": 9223372036854775807`.
**Ergebnis**: BLOCKIERT — Kein explizites Maximum, aber der Wert wird als PHP `int` gespeichert. Für Produktionsanwendungen ein maximales Limit hinzufügen (z.B. 999_999_999 Cent = ~10 Mio. in einer Währung).

---

### ATK-12 — Währungszeichenkette mit Injection-Payload 🚫 BLOCKIERT

**Angriff**: Sende `"currency": "'; DROP TABLE price_tiers; --"`.
**Ergebnis**: BLOCKIERT — Währung wird als gebundener Parameterwert übergeben. Für Produktionsanwendungen gegen eine ISO-4217-Allowlist validieren.

---

### ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| ATK-01 | SQL-Injection via Betrag | 🚫 BLOCKIERT |
| ATK-02 | Negativer Betrag | 🚫 BLOCKIERT |
| ATK-03 | Null-Betrag | 🚫 BLOCKIERT |
| ATK-04 | Ungültiges Datumsformat | 🚫 BLOCKIERT |
| ATK-05 | Rückwirkendes effective_from | 🚫 BLOCKIERT |
| ATK-06 | Integer-Überlauf bei Produkt-ID | 🚫 BLOCKIERT |
| ATK-07 | Gleitkomma-Produkt-ID | 🚫 BLOCKIERT |
| ATK-08 | Datums-Injection-Payload | 🚫 BLOCKIERT |
| ATK-09 | Fremde Produkt-ID | 🚫 BLOCKIERT |
| ATK-10 | Gleichzeitiger Preis-SET | 🚫 BLOCKIERT |
| ATK-11 | Extrem großer Betrag | 🚫 BLOCKIERT |
| ATK-12 | Währungs-Injection | 🚫 BLOCKIERT |

**12 BLOCKIERT, 0 EXPONIERT**
