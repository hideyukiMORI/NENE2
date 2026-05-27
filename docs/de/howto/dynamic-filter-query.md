# How-to: Dynamische Filter-Abfrage (Dynamische WHERE-Klausel)

> **Verwandte Szenarien**: DX Szenario 03, 18, 22, 25, 29, 30, 33, 37, 38, 41, 47, 48 — die am häufigsten zitierte fehlende Anleitung in 50 DX-Szenarien.

Viele List-Endpunkte akzeptieren optionale Query-Parameter, die in SQL-Bedingungen übersetzt werden.
Die Hauptherausforderung: wenn ein Parameter fehlt (`null`), muss die Bedingung **vollständig übersprungen** werden — nicht gegen `NULL` in SQL verglichen.

Diese Anleitung zeigt das kanonische Muster, das in NENE2-Anleitungen verwendet wird.

---

## Das Kernmuster: `$conditions`-Array + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // erforderliche Bedingung — immer enthalten
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($categoryId !== null) {
        $conditions[] = 'category_id = ?';
        $bindings[]   = $categoryId;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $bindings[]   = "%{$keyword}%";
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC",
        $bindings,
    );
}
```

**Warum das funktioniert**:
- `$conditions` hat immer mindestens ein Element (die erforderliche Bedingung), sodass `implode(' AND ', $conditions)` nie einen leeren String produziert.
- Jeder optionale Block fügt sowohl das SQL-Fragment als auch seinen Bindungswert hinzu — sie bleiben synchron.
- Wenn alle optionalen Parameter `null` sind, reduziert sich die Abfrage auf `WHERE deleted_at IS NULL`.

---

## Anti-Muster: `WHERE 1=1`

Eine verbreitete Alternative ist `WHERE 1=1` als Startwert, dann immer `AND` anhängen:

```php
// Funktioniert, aber weniger klar:
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

Das funktioniert auch. Der `$conditions`-Array-Ansatz wird bevorzugt, weil er SQL-Fragmente sauber von ihren Bindungen trennt und es einfacher macht, jede Bedingung isoliert zu testen.

---

## Bereichsbedingungen: Min/Max-Filter

Preisbereich, Datumsbereich und ähnliche `>=` / `<=` Filter:

```php
public function searchProperties(
    ?int    $priceMin,
    ?int    $priceMax,
    ?int    $bedroomsMin,
    ?string $dateFrom,
    ?string $dateTo,
): array {
    $conditions = ['status = ?'];
    $bindings   = ['available'];

    if ($priceMin !== null) {
        $conditions[] = 'price_yen >= ?';
        $bindings[]   = $priceMin;
    }

    if ($priceMax !== null) {
        $conditions[] = 'price_yen <= ?';
        $bindings[]   = $priceMax;
    }

    if ($bedroomsMin !== null) {
        $conditions[] = 'bedrooms >= ?';
        $bindings[]   = $bedroomsMin;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'available_from >= ?';
        $bindings[]   = $dateFrom;
    }

    if ($dateTo !== null) {
        $conditions[] = 'available_from <= ?';
        $bindings[]   = $dateTo;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM properties {$where} ORDER BY price_yen ASC",
        $bindings,
    );
}
```

`min`- und `max`-Bedingungen separat statt `BETWEEN` verwenden — das ermöglicht es dem Client, nur eine Grenze anzugeben (z. B. "Preis bis 5M, keine Untergrenze").

---

## Enum / Allowlist-Filter

Wenn ein Parameterwert aus einer festen Menge kommen muss, vor dem Hinzufügen zu `$conditions` validieren:

```php
private const VALID_STATUSES = ['draft', 'published', 'archived'];

public function listByStatus(?string $status): array
{
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    // ...
}
```

`$status` **nicht** direkt in den SQL-String interpolieren, auch wenn es sicher aussieht. Immer einen Bind-Parameter (`?`) verwenden und PDO das Quoting überlassen.

---

## IN-Klausel: Multi-Wert-Filter

Wenn der Client mehrere Werte übergeben kann (z. B. `?category_ids[]=1&category_ids[]=3`):

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // kein Filter — alles zurückgeben
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` generiert die korrekte Anzahl von `?`-Platzhaltern.
Niemals `implode(',', $categoryIds)` verwenden, um einen `IN (1,2,3)`-String zu erstellen — das ist SQL-Injection.

Für AND-Semantik (Elemente, die **alle** gegebenen Tags erfüllen), siehe [`multi-value-tag-filter.md`](multi-value-tag-filter.md).

---

## Sicheres ORDER BY: Allowlist-Interpolation

`ORDER BY`-Spaltennamen **können keine** Bind-Parameter verwenden — sie müssen interpoliert werden.
Immer gegen eine Allowlist validieren:

```php
private const SORT_COLUMNS  = ['name', 'price_yen', 'created_at'];
private const SORT_DIRECTION = ['asc', 'desc'];

public function list(?string $sortBy, ?string $order): array
{
    $col = in_array($sortBy, self::SORT_COLUMNS, true)  ? $sortBy : 'created_at';
    $dir = in_array($order,  self::SORT_DIRECTION, true) ? $order  : 'desc';

    $conditions = ['deleted_at IS NULL'];

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY {$col} {$dir}",
        [],
    );
}
```

Für eine vollständige Behandlung der ORDER-BY-Injection-Prävention siehe [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md).

---

## Filter mit Paginierung kombinieren

Ein häufiges Muster — dynamischer Filter + Cursor- oder Offset-Paginierung:

```php
public function paginatedSearch(
    ?string $status,
    ?string $keyword,
    int     $limit,
    int     $offset,
): array {
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = 'title LIKE ?';
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // Count-Abfrage verwendet dieselbe WHERE-Klausel
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // Datenabfrage fügt LIMIT/OFFSET hinzu — NICHT zu $bindings vor COUNT hinzufügen
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

Zuerst `$bindings` für die Filterbedingungen aufbauen, dann in die `COUNT`-Abfrage und die Datenabfrage einstreuen. `$limit` und `$offset` nur an die Datenabfrage anhängen.

---

## Optionale Query-Parameter parsen

`QueryStringParser`-Helfer verwenden, um `null`-sichere typisierte Werte aus dem Request zu erhalten:

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

Alle Helfer geben `null` zurück, wenn der Parameter fehlt oder nicht zum Zieltyp geparst werden kann. Diese nullable Werte direkt an die Repository-Methode übergeben — die Methode überspringt Bedingungen, bei denen der Wert `null` ist.

---

## Häufige Fehler

| Fehler | Problem | Lösung |
|---------|---------|-----|
| `WHERE status = ?` mit `null`-Bindung | SQLite wertet `status = NULL` aus → immer false (sollte `IS NULL` sein) | Bedingung überspringen, wenn der Wert `null` ist; `IS NULL` nur verwenden, wenn explizit NULL-Zeilen gewünscht werden |
| `WHERE 1=1` ohne erforderliche Bedingung | Gibt alle Zeilen zurück, wenn alle optionalen Parameter fehlen und kein Tenant/Owner-Filter vorhanden ist | Immer mindestens eine erforderliche Bedingung einschließen (Tenant, Owner, deleted_at) |
| `$status` direkt interpolieren | SQL-Injection | Immer `?`-Bind-Parameter verwenden |
| `IN (implode(',', $ids))` | SQL-Injection | `array_fill` + `?`-Platzhalter verwenden |
| `LIMIT`/`OFFSET` vor `COUNT(*)` zu `$bindings` hinzufügen | COUNT bekommt falsche Ergebnisse | Zuerst Filter-`$bindings` aufbauen; in COUNT einstreuen, dann LIMIT/OFFSET für Datenabfrage anhängen |

---

## Verwandte Anleitungen

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — AND / OR Semantik für N:M-Tag-Filter (`HAVING COUNT(DISTINCT)`)
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) — sicheres ORDER BY mit Allowlist
- [`add-pagination.md`](add-pagination.md) — Kombination mit Offset- / Cursor-Paginierung
- [`contact-management.md`](contact-management.md) — vollständiges Beispiel mit LIKE + EXISTS-Filter
