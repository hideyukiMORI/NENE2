# How-to: Aggregierte Berichts-API

> **FT-Referenz**: FT245 (`NENE2-FT/agglog`) — Aggregierte Berichts-API

Demonstriert eine mehrdimensionale aggregierte Berichts-API, bei der eine einzelne Bestelltabelle
in Zusammenfassungssummen, tägliche Aufschlüsselung, Statusverteilung und Top-Artikel unterteilt wird —
alles mit optionaler Datumsbereichsfilterung, `COALESCE` für nullsichere Aggregationen und
`COUNT(CASE WHEN...)` für bedingte Zählungen ohne Unterabfragen.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|----------------------|------------------------------------------------------|
| `POST` | `/orders` | Bestellung erfassen |
| `GET` | `/reports/summary` | Gesamtbestellungen, Umsatz, Durchschnittswert, abgeschlossene Bestellungen |
| `GET` | `/reports/daily` | Umsatz und Bestellanzahl pro Tag |
| `GET` | `/reports/by-status` | Bestellanzahl und Umsatz nach Status gruppiert |
| `GET` | `/reports/top-items` | Top-Artikel nach Umsatz (begrenzt, eingestuft) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` wird auf DB-Ebene durch ein `CHECK` als Sicherheitsnetz eingeschränkt. `amount` wird
als Ganzzahl gespeichert (kleinste Währungseinheit). `created_at` ist ein ISO-String — Datumsvergleiche
nutzen die String-Ordnung im `YYYY-MM-DD`-Format, das lexikografisch konsistent mit der chronologischen
Reihenfolge ist.

---

## Zusammenfassungsaggregation: `COALESCE` + `COUNT(CASE WHEN ...)`

Der Zusammenfassungs-Endpunkt gibt mehrere aggregierte Kennzahlen in einer einzigen Abfrage zurück:

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` — gibt `0` statt `NULL` zurück, wenn die Tabelle keine passenden
Zeilen hat. `SUM()` und `AVG()` geben bei leeren Mengen `NULL` zurück; `COALESCE` wandelt dies
in eine sichere Null um.

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` — zählt nur Zeilen, bei denen `status =
'completed'`, ohne Unterabfrage oder zweiten Durchlauf. `CASE WHEN` gibt für nicht passende Zeilen
`NULL` zurück; `COUNT` ignoriert `NULL`, sodass nur abgeschlossene Bestellungen gezählt werden.

Dies entspricht einem gefilterten `COUNT`, läuft aber in einem einzigen Scan, was es effizienter
als separate Abfragen für jeden Status macht.

---

## Tägliche Aufschlüsselung: `substr()` zur Datumskürzung

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` extrahiert die ersten 10 Zeichen (`YYYY-MM-DD`) aus dem
ISO-Datetime-String und gruppiert alle Ereignisse am gleichen Kalendertag. Dies ist eine
Alternative zu SQLites `strftime('%Y-%m-%d', created_at)` für Timestamp-Strings im ISO-8601-Format
mit festem Präfix.

`GROUP BY date` verwendet den Alias — SQLite unterstützt Aliasing in `GROUP BY` (im Gegensatz zu
einigen anderen Datenbanken, die den Ausdruck wiederholen müssen).

---

## Statusverteilung: `GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` platziert den häufigsten Status zuerst. Das Ergebnis hat maximal
so viele Zeilen, wie es unterschiedliche Statuswerte gibt (vier in diesem Schema).

---

## Top-Artikel: nach Umsatz eingestuft mit `LIMIT`

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` — parametrisiertes `LIMIT` wählt die Top-N-Artikel nach
Gesamtumsatz aus. Der `limit`-Pfadparameter wird serverseitig begrenzt:

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` verhindert, dass Clients mehr als 100 Artikel anfordern. Hinweis:
`is_numeric($q['limit'])` wird hier (statt `is_int`) verwendet, weil Query-String-Werte immer
Strings sind — `is_int` würde bei Query-String-Eingaben immer fehlschlagen.

---

## Dynamische `WHERE`-Klausel mit `dateFilter()`

Alle Aggregationsabfragen teilen einen `dateFilter()`-Helfer, der Bedingungen nur dann anfügt,
wenn eine Datumsgrenze angegeben ist:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

Wenn beide `from` und `to` `null` sind, ist `$where` `''` — die gesamte Tabelle wird gescannt.
Der Aufrufer bettet `{$where}` in den SQL-String ein, bevor die Abfrage ausgeführt wird. Die
tatsächlichen Werte sind trotzdem parametrisiert (`?`) — nur das `WHERE`-Schlüsselwort wird interpoliert.

---

## Datumsvalidierung: `createFromFormat()`-Hin-und-Rückweg

Das Akzeptieren von `from` und `to` als YYYY-MM-DD-Strings erfordert die Validierung, dass das Datum
sowohl wohlgeformt als auch semantisch gültig ist (z.B. wird `2026-02-30` abgelehnt):

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

Zweistufige Validierung:
1. `preg_match` — lehnt nicht passendes Format schnell ohne Datumsobjekt-Overhead ab.
2. `createFromFormat` + Hin-und-Rückweg `format()` — erkennt semantisch ungültige Daten wie
   `2026-02-30` (das PHP auf `2026-03-02` überläuft, wenn nur per Regex validiert).

Die Bereichsrichtung wird ebenfalls validiert:
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

Der String-Vergleich funktioniert hier korrekt, weil beide Daten `YYYY-MM-DD` sind — ein Format,
bei dem die lexikografische Reihenfolge der chronologischen entspricht.

---

## Verwendete `NENE2`-Bausteine

| Baustein | Zweck |
|---|---|
| `ValidationException` / `ValidationError` | Strukturiertes `422` mit `errors`-Array |
| `JsonResponseFactory::create()` | JSON-Antwort kodieren |
| `Router`-Konstanten | `PARAMETERS_ATTRIBUTE` für Pfadparameter |

---

## Verwandte Anleitungen

- [`event-analytics-api.md`](event-analytics-api.md) — JSON-Blob-Analytics mit `json_extract()`, `COUNT(DISTINCT)`-Gruppierung
- [`cqrs-pattern.md`](cqrs-pattern.md) — SQL-VIEW als Lesemodell für Bestellaggregation
- [`credit-ledger.md`](credit-ledger.md) — `COALESCE(SUM(amount * direction), 0)`-Saldoberechnung
- [`admin-report-aggregation.md`](admin-report-aggregation.md) — Admin-bezogene Aggregationsmuster
