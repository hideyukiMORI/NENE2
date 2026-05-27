# Admin-Berichtsaggregation hinzufügen

Dashboard-ähnliche Aggregations-Endpunkte mit Datumsbereichen, Gruppierung und Limit-Clamping erstellen.

## Schema

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/orders` | Bestellung einfügen |
| `GET` | `/reports/summary` | Gesamtbestellungen, Umsatz, Durchschnitt, abgeschlossene Bestellungen |
| `GET` | `/reports/daily` | Bestellungen nach Datum gruppiert |
| `GET` | `/reports/by-status` | Bestellungen nach Status gruppiert |
| `GET` | `/reports/top-items` | Top-N-Artikel nach Umsatz |

Query-Parameter (alle Berichte): `from=YYYY-MM-DD`, `to=YYYY-MM-DD`

## Datumsbereichsfilter (sicher parametrisiert)

Die WHERE-Klausel dynamisch aufbauen, Werte als gebundene Parameter übergeben — niemals interpolieren:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## Datumsvalidierung (Schutz vor Injection)

Nicht-ISO-8601-Daten ablehnen, bevor sie die Abfrage erreichen:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // 2026-13-01 ablehnen
}
```

`from > to` ablehnen:

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## Limit-Clamping

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## Aggregationsabfragen

**Zusammenfassung**:
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**Tägliche Aufschlüsselung** (SQLite-Datum-Substring):
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**Top-Artikel**:
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## Sicherheitshinweise

- Alle Query-Parameter werden vor der Verwendung validiert — SQL-Injection über `from`/`to`/`limit` wird mit 422 abgelehnt.
- Artikelnamen und Kunden-IDs werden über parametrisierte Abfragen gespeichert — Sonderzeichen und Injection-Versuche sind wörtliche Strings.
- `COALESCE(SUM(...), 0)` verhindert NULL in Zusammenfassungen, wenn keine Zeilen passen.
- Limit auf `MAX_LIMIT` begrenzt — verhindert Ressourcenerschöpfung durch große `LIMIT`-Werte.
