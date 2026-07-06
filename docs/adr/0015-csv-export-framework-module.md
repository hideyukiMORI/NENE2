# ADR 0015: CSV export framework module (`Nene2\Export`)

## Status

accepted

## Context

Every NeNe product that exports data offers a CSV download, and by mid-2026 the
fleet had grown **nine hand-rolled `fputcsv` sites across six products**
(`_work/reports/2026-07-06/upstream-design/03-csv-helper.md`): `nene-clear`
(four near-identical `buildCsv` copies), `nene-profile`, `nene-vault`,
`nene-deal`, `nene-invoice` (a shared `Support/Csv.php`), and `nene-field`. They
converged on the same idea but diverged in quality across four axes, and two of
those divergences are correctness/security bugs, not mere style:

- **Formula injection (the headline).** Only `nene-invoice` and `nene-profile`
  neutralise spreadsheet formula injection. The other four export
  **user-controlled text** unprotected: clear's `counterparty_text` (payer-chosen
  transfer name) and `recipient_email`; vault's `counterparty_name` / `category`;
  deal's audit `before`/`after` (arbitrary user strings — the most direct
  injection path); field's `actor_name`. A cell like `=cmd|'/c calc'!A1` landing
  in a transfer name or audit diff can execute when the file is opened. This is a
  real, fleet-wide vulnerability that a single shared writer closes everywhere at
  once.
- **RFC 4180 / PHP 8.4 escape.** Products split between the RFC-4180-correct
  `escape: ''` (vault/deal/field) and PHP's legacy backslash escape
  (clear/profile/invoice). PHP 8.4 **deprecates a non-empty `fputcsv` escape**, so
  the backslash form is both a future incompatibility and a source of malformed
  CSV whenever data contains `\`.
- **BOM.** Present in clear/deal/invoice/field, absent in profile/vault — Excel-JP
  mojibake behaviour differs per product.
- **Memory.** No site streams; all buffer the whole export in `php://temp`
  (clear even batch-fetches 1000 rows then accumulates every row anyway), an OOM
  risk on large exports.

`nene-invoice/src/Support/Csv.php` is the best existing prototype (type-based
neutralisation, numeric pass-through) but is per-row only — no BOM, no header, no
streaming, no explicit RFC 4180 escape. NENE2 core ships **no** CSV/export
component today, so this is a clean new namespace with no internal competitor.

## Decision

Add a `Nene2\Export\CsvWriter` (registered on the ADR 0009 public surface) that
makes the safe behaviour the default and the only easy path.

### Injection neutralisation is on by default and type-based

A string cell whose first character is a formula trigger (`=`, `+`, `-`, `@`,
TAB, CR) is prefixed with a single quote. Neutralisation keys off the **PHP
type**, not the column name: only strings are touched; `int` / `float` / `bool` /
`null` pass through untouched. This is deliberately stronger than profile's
column-allowlist approach — type-based cannot miss a newly added text column, and
it preserves numeric data exactly. The discriminating case: the string `"-1200"`
(possibly attacker text) is neutralised to `'-1200`, while the integer `-1200`
(a genuine amount) stays `-1200`. Promoting invoice's prototype, not profile's, is
what makes numeric pass-through safe by construction.

`sanitizeFormulas: false` is available for fully trusted, machine-generated data,
but the default is on.

### RFC 4180 quoting, with the escape fixed (not configurable)

The writer calls `fputcsv(..., escape: '')`, which doubles embedded enclosures
per RFC 4180 and avoids PHP 8.4's non-empty-escape deprecation. The escape is a
**private constant, not a constructor option**: exposing it would let a consumer
re-introduce the deprecated backslash escaping and reopen the malformed-CSV bug.
This is the one place the module intentionally departs from the design proposal's
sketched signature (which listed `escape` as a parameter).

### BOM is an option, on by default

`bom: true` matches the majority of the fleet and is the Excel-JP-safe choice.
It is a per-writer flag so a product whose downstream parser does not expect a BOM
can disable it. Fleet-wide BOM unification is intentionally **not** forced here.

### Streaming via `iterable`

`writeAll(iterable $rows)` accepts a generator, so a repository can `yield` in
batches and the writer streams to the destination (`php://output`, a PSR-7 body,
`php://temp`) in O(batch) memory. `writeRow()` is available for incremental /
custom row-expansion cases (e.g. deal's one-audit-row-to-many-field-rows). The
BOM and header row are emitted lazily on first write, so an empty export is still
a valid header-only CSV rather than a zero-byte file.

### Surface

```php
namespace Nene2\Export;

final class CsvWriter
{
    /** @param resource $stream  @param list<string> $headers */
    public function __construct(
        $stream,
        array $headers = [],
        bool $bom = true,
        bool $sanitizeFormulas = true,
    );

    /** @param list<string|int|float|bool|null> $row */
    public function writeRow(array $row): void;

    /** @param iterable<list<string|int|float|bool|null>> $rows */
    public function writeAll(iterable $rows): void;
}
```

### Scope (v1)

The writer only. Consumer migration of the nine sites is a **separate PR per
product** (recommended order: invoice → clear → deal/vault/field/profile), and
true repository-level streaming (adding `yield` iterators) plus fleet-wide BOM
unification are deliberately **later issues** so the first migrations stay
behaviour-equivalent apart from the injection fix.

## Consequences

**Benefits**

- One tested writer makes formula-injection neutralisation, RFC 4180 quoting, and
  the PHP 8.4 escape fix structural rather than per-product discipline; the four
  unprotected products are fixed the moment they adopt it.
- Type-based neutralisation cannot miss a text column and never corrupts numeric
  data.
- Streaming is available without buffering the whole export.

**Costs / follow-up**

- Six products migrate off their hand-rolled code — one follow-up PR each.
- Neutralisation has a visible side effect: a legitimate string cell starting with
  `-`/`+`/`=`/`@` (e.g. a `-未定` text value) gains a leading `'`. Numeric columns
  are unaffected (type pass-through). Migration PRs should carry a golden-file
  regression to confirm only the intended byte differences (BOM, escape,
  neutralisation) appear.

## Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| **Expose `escape` as a constructor option** (as the design proposal sketched) | Would let a consumer opt back into PHP's deprecated non-empty escape and the malformed-CSV / non-RFC-4180 behaviour. Fixing it to `''` is the whole point. |
| **Column-allowlist neutralisation** (profile's approach) | Misses any text column not on the list; a new field ships unprotected. Type-based (string vs numeric) is safe by default and preserves numbers. |
| **Neutralise numeric cells too** | Would quote genuine negative amounts (`-1200`), corrupting numeric columns. The type split exists precisely to avoid this. |
| **Default `sanitizeFormulas` off** | The headline value is closing a live fleet-wide vulnerability; safe-by-default is required. Off is opt-in for trusted data only. |
| **Also ship a PSR-7 `CsvResponder` façade now** | Response assembly differs per product and pulls in response/stream factories; keep v1 to the writer and let products own their response layer. Revisit if duplication warrants it. |
| **Upstream true streaming repositories now** | Requires per-product repository `yield` I/F changes; split to a later issue so the first migrations are behaviour-equivalent plus the injection fix only. |
| **Include profile's bank-CSV *import*** | Out of scope — import is product-specific domain parsing, not export. This module is export only. |

## Related

- Issue: `#1504`
- Design proposal: `_work/reports/2026-07-06/upstream-design/03-csv-helper.md`
- Backend audit: `_work/reports/2026-07-05/nene-backend-audit.md` §4-4
- Prototype promoted: `nene-invoice/src/Support/Csv.php` (type-based neutralisation, numeric pass-through)
- See also: ADR 0009 (public API scope — updated with the `Nene2\Export` row), ADR 0014 (audit module — same upstreaming pattern)
- Supersedes: none
- Superseded by: none
