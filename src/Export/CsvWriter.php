<?php

declare(strict_types=1);

namespace Nene2\Export;

/**
 * Streaming CSV writer with safe defaults (ADR 0015).
 *
 * Consolidates three properties that products had been getting wrong or
 * re-deriving by hand around raw `fputcsv`:
 *
 * 1. **Formula-injection neutralisation (default on).** A string cell whose
 *    first character is a spreadsheet formula trigger (`=`, `+`, `-`, `@`, TAB,
 *    CR) is prefixed with a single quote, so Excel / LibreOffice / Google Sheets
 *    render it as text instead of executing it. Neutralisation is **type-based**:
 *    only strings are touched — `int` / `float` / `bool` / `null` pass through
 *    untouched, so a genuine negative number like `-1200` stays numeric while the
 *    string `"-1200"` (which could be attacker-controlled text) is neutralised.
 * 2. **RFC 4180 quoting.** Embedded quotes are doubled and no backslash escaping
 *    is used, by passing an empty escape to `fputcsv`. This is both the correct
 *    RFC 4180 behaviour and the only way to avoid PHP 8.4's deprecation of a
 *    non-empty `fputcsv` escape argument. The escape is fixed, not configurable,
 *    so no consumer can re-introduce the legacy backslash behaviour.
 * 3. **Optional UTF-8 BOM (default on).** Excel on Japanese locales reads UTF-8
 *    correctly only when a BOM is present; the default matches the majority of
 *    the fleet's exports. Disable it for pipelines whose parser does not expect a
 *    BOM.
 *
 * The writer streams: {@see writeAll()} accepts any iterable — including a
 * generator — so a repository that yields rows in batches never has to
 * materialise the whole result set in memory.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0015).
 */
final class CsvWriter
{
    /** Leading characters that make a spreadsheet treat a cell as a formula. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** UTF-8 byte-order mark. */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * RFC 4180 uses no escape character — embedded enclosures are doubled.
     * Passing an empty escape to `fputcsv` selects that behaviour and side-steps
     * PHP 8.4's deprecation of a non-empty escape argument. Intentionally a
     * constant, not a constructor option: exposing it would let a consumer opt
     * back into the deprecated, non-RFC-4180 backslash escaping.
     */
    private const ESCAPE = '';

    /** @var resource */
    private $stream;

    private bool $prologueWritten = false;

    /**
     * @param resource     $stream           write destination (`php://output`, `php://temp`, a PSR-7 body, ...)
     * @param list<string> $headers          header row, emitted before the first data row; empty means no header row
     * @param bool         $bom              prepend a UTF-8 BOM (Excel-JP safe default)
     * @param bool         $sanitizeFormulas neutralise formula-injection in string cells (default on; keep on unless the data is fully trusted)
     */
    public function __construct(
        $stream,
        private readonly array $headers = [],
        private readonly bool $bom = true,
        private readonly bool $sanitizeFormulas = true,
    ) {
        $this->stream = $stream;
    }

    /**
     * Writes a single data row. The BOM and header row (if any) are emitted lazily
     * on the first call, so constructing a writer has no side effect on the stream.
     *
     * @param list<string|int|float|bool|null> $row
     */
    public function writeRow(array $row): void
    {
        $this->writePrologue();
        $this->put($row);
    }

    /**
     * Writes every row from an iterable. Because it accepts a generator, a
     * repository can `yield` rows in batches and this streams them straight to the
     * destination in O(batch) memory instead of buffering the whole export.
     *
     * Calling this with an empty iterable still emits the BOM and header row, so an
     * empty export is a valid header-only CSV rather than a zero-byte file.
     *
     * @param iterable<list<string|int|float|bool|null>> $rows
     */
    public function writeAll(iterable $rows): void
    {
        $this->writePrologue();

        foreach ($rows as $row) {
            $this->put($row);
        }
    }

    /** Emits the BOM and header row exactly once, before any data row. */
    private function writePrologue(): void
    {
        if ($this->prologueWritten) {
            return;
        }

        $this->prologueWritten = true;

        if ($this->bom) {
            fwrite($this->stream, self::BOM);
        }

        if ($this->headers !== []) {
            $this->put($this->headers);
        }
    }

    /** @param list<string|int|float|bool|null> $fields */
    private function put(array $fields): void
    {
        if ($this->sanitizeFormulas) {
            $fields = array_map(
                fn (string|int|float|bool|null $field): string|int|float|bool|null => is_string($field)
                    ? $this->neutralize($field)
                    : $field,
                $fields,
            );
        }

        fputcsv($this->stream, $fields, ',', '"', self::ESCAPE);
    }

    /** Prefixes a single quote when a string cell would otherwise parse as a formula. */
    private function neutralize(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
