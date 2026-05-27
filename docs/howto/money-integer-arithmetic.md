# How-to: Money and Integer Arithmetic

> **Related scenarios**: DX Scenario 10, 23, 32, 36, 40, 43, 44, 50 — the most frequently cited source of silent precision bugs across financial scenarios.

Monetary amounts stored as floating-point (`REAL` / `float`) accumulate rounding errors.
`1001 * 0.05` in IEEE 754 produces `50.049999999999997`, not `50.05`.
The correct approach is to store and calculate amounts as **integers in the smallest currency unit**
(yen for JPY, cents for USD/EUR).

---

## The rule: always store as integer

```php
// ❌ Wrong — REAL/float accumulates errors
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ Correct — integer arithmetic
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50 (truncated)
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

Schema:

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ INTEGER, not REAL
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

Use `CHECK` constraints to enforce non-negative values at the DB level.

---

## Choosing the rounding function

When dividing integers, you must decide how to handle the remainder.
**Decide and document this policy before writing code** — changing it later affects
every historical record.

| Function | Behavior | Example: `intdiv(1, 3)` | When to use |
|----------|----------|------------------------|-------------|
| `intdiv($a, $b)` | Truncate toward zero | `0` | Platform fees (payer keeps remainder) |
| `(int) round($a / $b)` | Round half-up | `0` (rounds to 0) | Split bills, generic rounding |
| `(int) ceil($a / $b)` | Round up (ceiling) | `1` | Tax calculation (always round up for government) |
| `(int) floor($a / $b)` | Round down (floor) | `0` | Same as intdiv for positive values |

### Platform fee (5%) — who keeps the remainder?

```php
// Option A: platform takes floor (payer favored)
$fee = intdiv($amount * 5, 100);     // 1001 yen → fee = 50, seller gets 951

// Option B: platform takes ceil (platform favored)
$fee = (int) ceil($amount * 5 / 100); // 1001 yen → fee = 51, seller gets 950

// Option C: round half-up (neutral)
$fee = (int) round($amount * 5 / 100); // 1001 yen → fee = 50, seller gets 951
```

There is no universally correct answer. **Document the choice in the API spec.**

---

## Tax calculation (Japanese consumption tax: 10%)

Japanese consumption tax requires **rounding down** per transaction (not per line item):

```php
// ✅ Truncate at transaction level
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ Do NOT round per line item and then sum — rounding errors accumulate
$items = [100, 100, 100]; // 3 items × 100 yen
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // may differ from intdiv(300 * 110, 100)
```

If storing a `tax_rate`, store it as **basis points** (integer, 1/10000):
`10% = 1000 bps`. Avoids floating-point in rate storage itself.

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10.00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## Split / divvy: remainder distribution

When splitting a total among N participants:

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // each person's share (truncated)
    $remainder = $totalYen % $n;              // leftover yen (0 to n-1)

    $shares = array_fill(0, $n, $base);

    // Distribute remainder 1 yen at a time to the first participants
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // Verify: sum must equal original total
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (sum = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (sum = 100)  ✅
```

Never use `round($total / $n)` for each participant and call it done —
the sum will often be off by 1 yen.

---

## SQLite integer division trap

In SQLite, dividing two integers performs integer division:

```sql
SELECT 5 / 100;     -- → 0  (integer division: truncates)
SELECT 5.0 / 100;   -- → 0.05 (real division)
SELECT 5 * 100 / 100;  -- → 5 (multiply first, then divide — OK)
```

**In PHP** with PDO, all bound values are sent as strings. SQLite coerces them, but:

```php
// Safe: multiply first to avoid truncation
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 first (integer * integer = integer), then / 100

// Risky: if PDO sends '5' and '100' as strings, SQLite may choose real division
// Test this if your SQLite version or PDO behavior is unclear.
```

The safest approach: **do the arithmetic in PHP with `intdiv()`**, store the result,
and only use SQL arithmetic for summation (`SUM`, `COUNT`), not for per-row computation.

---

## Depreciation (straight-line)

```php
// Annual depreciation (straight-line method)
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// Current book value
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` truncates the annual depreciation, which means the asset depreciates slightly
less per year and the remainder shows up as the final year's extra depreciation.
This is the standard behavior for Japanese straight-line depreciation.

---

## Displaying to the user

Convert to a human-readable format only at the response layer, never in the domain:

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1,234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

Store `amountYen` (integer) for further computation; `displayAmount` (string) for the UI.
Never store formatted strings — they cannot be summed.

---

## Summary: decision checklist

Before writing any monetary calculation, answer these questions:

1. **Unit**: yen (no decimal), cents (1/100), or micropennies (1/1000)?
   → Store as integer in that unit; document the unit in the column name (`amount_yen`, `price_cents`).

2. **Rounding direction**: `intdiv` (truncate), `ceil`, `floor`, or `round`?
   → Choose one; add a comment in the code explaining why.

3. **Who gets the remainder**: when splitting, who absorbs the rounding difference?
   → Distribute the remainder explicitly (see `splitEvenly` above).

4. **Tax rate storage**: basis points (`INTEGER`) not percent (`REAL`)?
   → `1000` for 10%, `800` for 8%, never `0.10` or `0.08`.

5. **Cumulative or per-transaction**: accumulate tax per line item or per invoice total?
   → Per-transaction (single `intdiv`) is standard for JPY invoices.

---

## Related howtos

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — double-entry ledger with `Money` value object
- [`point-ledger-api.md`](point-ledger-api.md) — point/credit system with integer amounts
- [`expense-tracking-api.md`](expense-tracking-api.md) — expense recording with integer yen
