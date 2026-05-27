# How-to: Ausgaben-Tracking-API

> **FT-Referenz**: FT311 (`NENE2-FT/expenselog`) — Ausgaben-Tracking: YYYY-MM-DD-Datumsformatvalidierung, Kategorie als offener String (kein Enum), monatliche Zusammenfassungsaggregation nach Kategorie, Offset-Paginierung mit limit/offset, PATCH-Teilaktualisierung (nur bereitgestellte Felder ändern), Datumsbereichsfilter, statische `/summary`-Route vor dynamischer `/{id}`, 34 Tests / 67 Assertions PASS.

Diese Anleitung zeigt, wie eine Ausgaben-Tracking-API mit Datumsfilterung, Kategorieaggregation, Paginierung und Teilaktualisierungen aufgebaut wird.

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- Cent
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

Indizes auf `date` und `category` unterstützen schnelles Filtern. `amount` in Integer-Cent vermeidet Gleitkommagenauigkeitsprobleme.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `GET` | `/expenses` | Auflisten mit optionalen Filtern + Paginierung |
| `POST` | `/expenses` | Ausgabe erstellen |
| `GET` | `/expenses/summary` | Monatliche Kategorieaggregation |
| `GET` | `/expenses/{id}` | Einzelne Ausgabe abrufen |
| `PATCH` | `/expenses/{id}` | Teilaktualisierung |
| `DELETE` | `/expenses/{id}` | Löschen |

**Routen-Reihenfolge**: `/expenses/summary` muss **vor** `/expenses/{id}` registriert werden — sonst wird `summary` als `id`-Parameter erfasst.

## Datumsvalidierung — YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

Nur das strenge `YYYY-MM-DD`-Format wird akzeptiert. ISO 8601-Strings mit Zeitkomponenten oder Zeitzonen-Offsets werden abgelehnt.

## Kategorie — Offener String (kein Enum)

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

Kategorien sind Freitext-Strings (kein geschlossenes Enum). Jeder nicht-leere String ist gültig, sodass Kategorien wie `"food"`, `"transport"`, `"entertainment"` ohne Schemaänderungen möglich sind.

## Monatliche Zusammenfassung — YYYY-MM-Format

```php
// Abfrage: SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

Monatsparameter-Format: `YYYY-MM`. Aggregiert alle Ausgaben in diesem Monat, gruppiert nach Kategorie.

## Paginierung — Offset-basiert

```php
$pagination = PaginationQueryParser::parse($request);
// Gibt zurück: { limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

Ungültiges `limit` (kein Integer, negativ, zu groß) → 422.

## PATCH — Teilaktualisierung

```php
// Nur Felder aktualisieren, die im Body vorhanden sind
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` unterscheidet "Feld nicht angegeben" von "Feld als null angegeben". Nur bereitgestellte Felder werden validiert und aktualisiert.

## Datumsbereichsfilter

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

Alle Filterparameter sind optional. Null bedeutet "kein Filter auf dieser Dimension".

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `/expenses/{id}` vor `/expenses/summary` registrieren | `"summary"` wird als `id` abgeglichen; Summary-Endpunkt nicht erreichbar |
| `amount` als FLOAT speichern | Gleitkommagenauigkeit: `0.1 + 0.2 ≠ 0.3`; Integer-Cent verwenden |
| Beliebigen Datumsstring akzeptieren (ISO 8601 mit Zeit) | Inkonsistente Datumsvergleiche in WHERE-Klauseln |
| Geschlossenes Kategorie-Enum | Neue Kategorien erfordern Schemamigration |
| `isset($body['field'])` für PATCH | `isset()` gibt bei `null` false zurück; `array_key_exists()` verwenden |
| Zählabfrage ohne dieselben Filter wie Liste | Paginierungsgesamtanzahl stimmt nicht mit tatsächlicher gefilterter Anzahl überein |
| Kein Index auf date/category | Full-Table-Scan bei jeder gefilterten Listenanfrage |
