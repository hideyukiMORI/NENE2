# How-to: Dynamisches Sortieren & Filtern mit ORDER-BY-Injection-Prävention

> **FT-Referenz**: FT341 (`NENE2-FT/sortlog`) — Dynamische Sort/Filter-API mit SQL-ORDER-BY-Injection-Prävention via Allowlist, Status-Filter-Allowlist, ReDoS-immune O(n)-Validierung, 40+ Tests für VULN-A bis VULN-L und ATK-01 bis ATK-12, alle bestanden.

Diese Anleitung zeigt, wie ein sortierbarer, filterbarer List-Endpunkt sicher implementiert wird. Da `ORDER BY` in SQL keine parametrisierten Platzhalter verwenden kann, müssen Spalte und Richtung gegen eine strikte Allowlist validiert werden, bevor sie interpoliert werden.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## Endpunkt

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### Gültige Parameter

| Param | Erlaubte Werte | Standard |
|-------|---------------|---------|
| `sort` | `id`, `title`, `status`, `created_at` | `created_at` |
| `order` | `asc`, `desc` | `desc` |
| `status` | `draft`, `published`, `archived` | (alle) |
| `limit` | 1–100 | 20 |

## Antwort

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## Allowlist-Validierung — Das einzig sichere Muster

`ORDER BY`-Klauseln **können keine parametrisierten Bind-Werte verwenden**. Der Spaltenname muss direkt in SQL interpoliert werden. Das macht Allowlist-Validierung zwingend erforderlich.

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // Exakter String-Abgleich in der Allowlist — O(n), case-sensitiv, kein Regex
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // kein Filter
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**Warum `in_array()` statt Regex:**
- `in_array($v, $list, true)` ist O(n) — immun gegen ReDoS
- Regex `/^[a-z_]+$/` auf angreifer-kontrollierten 50-Zeichen-Payloads kann katastrophales Backtracking verursachen
- Striktes drittes Argument (`true`) aktiviert typensicheren Vergleich

### Groß-/Kleinschreibungsempfindlichkeit

Die Allowlist ist designbedingt case-sensitiv:

```php
GET /articles?sort=ID       → 422  // 'ID' nicht in der Allowlist
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ exakter Treffer
```

## Abfragekonstruktion

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // Status verwendet einen parametrisierten Platzhalter (sicher)
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// Sort-Spalte und Richtung kommen aus der Allowlist — sicher zu interpolieren
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` verwendet interpolierte Allowlist-Werte; `WHERE`-Klausel-Werte verwenden immer `?`-Platzhalter.

## Abgelehnte Payloads

### Injection-Muster → 422

```php
// SQL-Injection in sort
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // Kommentar
?sort=created_at%00                       → 422  // Null-Byte
?sort=1                                   → 422  // Spaltenindex (nicht in Allowlist)

// Richtungs-Injection
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// Status-Filter-Injection
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // muss exakter Statusname sein
?status=TRUE                              → 422

// Whitespace-Umgehung
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // führendes Leerzeichen

// Array-Injection (PSR-7)
?sort[]=created_at                        → 422  // Array, kein String
```

### Limit-Injection → 422

```php
?limit=999999           → 422  // überschreitet MAX_LIMIT=100
?limit=9999999999999999999999  → 422  // Überlauf (strlen > 18)
?limit=-1               → 422  // negativ
?limit=10.5             → 422  // Float
```

### Gültige Anfragen → 200

```php
GET /articles                                  → 200  // Standardwerte
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## Timing-Sicherheit

Jede Ablehnung ist augenblicklich (<100ms). Die Allowlist-Prüfung verwendet `in_array()`, das beim ersten Nicht-Treffer abbricht — kein Regex-Backtracking:

```php
// ReDoS-Payload: "aaaa...a!" (50 a's + '!')
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → sofort false
```

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `?sort=` direkt interpolieren: `ORDER BY $sort` | SQL-Injection — Angreifer kontrolliert die `ORDER BY`-Klausel vollständig |
| Nur mit Regex `/^[a-z_]+$/` validieren | ReDoS auf 50+-Zeichen-Payloads; erlaubt unbekannte Spaltennamen wie `password` |
| Groß-/Kleinschreibungsinsensitiver Vergleich (`strcasecmp`) | `ORDER BY CREATED_AT` ist gültiges SQL, umgeht aber case-sensitive Tests |
| `ORDER BY $sort` als Bind-Wert parametrisieren | Die meisten DB-Treiber behandeln es stillschweigend als Literal oder werfen einen Fehler |
| Nur `sort`, nicht `order`-Richtung in Allowlist | `order=asc; UNION SELECT ...` umgeht die Spaltenprüfung |
| `sort[]`-Array nach PSR-7-Parsing vertrauen | `implode(', ', $sort)` mit Array-Injection produziert Multi-Spalten-ORDER-BY |
| `status`-Filter-Allowlist weglassen | `status=admin' OR '1'='1` wird zu `WHERE status = 'admin' OR '1'='1'` |
