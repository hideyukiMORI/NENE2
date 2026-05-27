# How-to: Dynamic Filter Query (Dynamic WHERE Clause)

> **Related scenarios**: DX Scenario 03, 18, 22, 25, 29, 30, 33, 37, 38, 41, 47, 48 — the most frequently cited missing howto across 50 DX scenarios.

Many list endpoints accept optional query parameters that translate into SQL conditions.
The key challenge: when a parameter is absent (`null`), the condition must be **skipped entirely** — not compared against `NULL` in SQL.

This guide shows the canonical pattern used throughout NENE2 howtos.

---

## The Core Pattern: `$conditions` array + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // required condition — always included
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

**Why this works**:
- `$conditions` always has at least one element (the required condition), so `implode(' AND ', $conditions)` never produces an empty string.
- Each optional block appends both the SQL fragment and its binding value — they stay in sync.
- If all optional parameters are `null`, the query reduces to `WHERE deleted_at IS NULL`.

---

## Anti-pattern: `WHERE 1=1`

A common alternative is `WHERE 1=1` as the seed condition, then always appending `AND`:

```php
// Works, but less clear:
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

This also works. The `$conditions` array approach is preferred because it separates
SQL fragments from their bindings cleanly and is easier to test each condition in isolation.

---

## Range conditions: min/max filters

Price range, date range, and similar `>=` / `<=` filters:

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

Separate `min` and `max` conditions rather than `BETWEEN` — it lets the client
provide only one bound (e.g., "price up to 5M, no lower limit").

---

## Enum / allowlist filter

When a parameter value must come from a fixed set, validate before adding to `$conditions`:

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

Do **not** interpolate `$status` directly into the SQL string even if it looks safe.
Always use a bind parameter (`?`) and let PDO handle the quoting.

---

## IN clause: multi-value filter

When the client can pass multiple values (e.g., `?category_ids[]=1&category_ids[]=3`):

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // no filter — return everything
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` generates the correct number of `?` placeholders.
Never use `implode(',', $categoryIds)` to build an `IN (1,2,3)` string — that is SQL injection.

For AND semantics (items that match **all** given tags), see [`multi-value-tag-filter.md`](multi-value-tag-filter.md).

---

## Safe ORDER BY: allowlist interpolation

`ORDER BY` column names **cannot** use bind parameters — they must be interpolated.
Always validate against an allowlist:

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

See [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) for a full treatment
of ORDER BY injection prevention.

---

## Combining filter with pagination

A common pattern — dynamic filter + cursor or offset pagination:

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

    // Count query reuses the same WHERE
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // Data query appends LIMIT/OFFSET — do NOT add them to $bindings before COUNT
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

Build `$bindings` for the filter conditions first, then spread them into both the
`COUNT` query and the data query. Append `$limit` and `$offset` only to the data query.

---

## Parsing optional query parameters

Use `QueryStringParser` helpers to get `null`-safe typed values from the request:

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

All helpers return `null` when the parameter is absent or cannot be parsed to the target type.
Pass these `null`-able values directly to the repository method — the method skips
conditions where the value is `null`.

---

## Common mistakes

| Mistake | Problem | Fix |
|---------|---------|-----|
| `WHERE status = ?` with `null` binding | SQLite evaluates `status = NULL` → always false (should be `IS NULL`) | Skip condition when value is `null`; use `IS NULL` only when you explicitly want NULL rows |
| `WHERE 1=1` with no required condition | Leaks all rows if all optional params are absent and there's no tenant/owner filter | Always include at least one required condition (tenant, owner, deleted_at) |
| Interpolating `$status` directly | SQL injection | Always use `?` bind parameter |
| `IN (implode(',', $ids))` | SQL injection | Use `array_fill` + `?` placeholders |
| Adding `LIMIT`/`OFFSET` to `$bindings` before `COUNT(*)` | COUNT gets wrong results | Build filter `$bindings` first; spread into COUNT, then append LIMIT/OFFSET for data query |

---

## Related howtos

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — AND / OR semantics for N:M tag filters (`HAVING COUNT(DISTINCT)`)
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) — safe ORDER BY with allowlist
- [`add-pagination.md`](add-pagination.md) — combining with offset / cursor pagination
- [`contact-management.md`](contact-management.md) — complete example with LIKE + EXISTS filter
