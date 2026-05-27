# How-to: Ausgaben-Tracker-API

Diese Anleitung zeigt, wie ein persönliches Ausgaben-Tracking-System mit kategorienbasierter Filterung,
Datumsbereichsabfragen, monatlicher Zusammenfassungsaggregation und vollständigem CRUD mit NENE2 aufgebaut wird.
Muster demonstriert durch den **expenselog** Field Trial (FT223).

## Funktionen

- Ausgaben erstellen, lesen, aktualisieren, löschen (Datum, Betrag, Kategorie, Notiz)
- Auflisten mit Datumsbereichsfilter (`?from=` / `?to=`) und Kategoriefilter
- Monatliche Zusammenfassungsaggregation (Gesamt pro Kategorie pro Monat)
- Paginierung mit Gesamtanzahl
- Kategorie-Allowlist-Validierung
- Betragsvalidierung: positiver Integer (Cent)

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `GET` | `/expenses` | Ausgaben auflisten (filterbar, paginiert) |
| `POST` | `/expenses` | Ausgabe erstellen |
| `GET` | `/expenses/summary` | Monatliche Zusammenfassung nach Kategorie |
| `GET` | `/expenses/{id}` | Einzelne Ausgabe abrufen |
| `PATCH` | `/expenses/{id}` | Teilaktualisierung |
| `DELETE` | `/expenses/{id}` | Ausgabe löschen |

## Validierungsmuster

### Betrag (positiver Integer, in Cent gespeichert)

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

Die Verwendung von `is_int()` lehnt Floats aus JSON ab (`1.5` ist in PHPs striktem Modus kein Int).

### Datum (ISO 8601-Format)

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

Round-Trip-Validierung: parsen, dann neu formatieren — garantiert, dass der String kanonisch war.

### Kategorie-Allowlist

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## Datumsbereichsfilterung

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

Filter sind optional — für Abfragen über alle Zeiten weglassen. Daten werden lexikografisch verglichen (ISO 8601-Strings sind in UTC sortierbar).

## Monatliche Zusammenfassungsabfrage

Aggregation nach Jahr-Monat mit SQLites `strftime`:

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

Gibt Pro-Kategorie-Gesamtwerte für jeden Monat zurück:

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## PATCH-Teilaktualisierung

Nur im Body enthaltene Felder werden aktualisiert — fehlende Felder behalten ihre aktuellen Werte:

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// Gleiches Muster für date, category, note
```

## Validierungsmuster

| Feld | Prüfung | Grund |
|-------|-------|--------|
| `amount` | `is_int() && > 0` | Lehnt Floats, Null, Negative ab |
| `date` | Round-Trip `Y-m-d`-Parse | Nur kanonisches ISO 8601 |
| `category` | `in_array(strict: true)` | Verhindert Tippfehler und Injection |
| `limit` / `offset` | `max(1, min(100, $limit))` | Verhindert DoS und SQL-Injection |
