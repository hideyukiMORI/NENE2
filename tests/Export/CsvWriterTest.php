<?php

declare(strict_types=1);

namespace Nene2\Tests\Export;

use Nene2\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    /**
     * @param list<string>                              $headers
     * @param iterable<list<string|int|float|bool|null>> $rows
     */
    private function render(
        array $headers,
        iterable $rows,
        bool $bom = true,
        bool $sanitize = true,
    ): string {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        (new CsvWriter($stream, $headers, $bom, $sanitize))->writeAll($rows);

        rewind($stream);

        return stream_get_contents($stream) ?: '';
    }

    /** @return list<string> */
    private function dataLines(string $csv): array
    {
        return explode("\n", rtrim($csv, "\n"));
    }

    public function testNeutralisesAsciiFormulaTriggersInStringCells(): void
    {
        $out = $this->render([], [
            ['=cmd|calc'],
            ['+1+2'],
            ['-1+2'],
            ['@SUM(A1)'],
        ], bom: false);

        self::assertSame(
            ["'=cmd|calc", "'+1+2", "'-1+2", "'@SUM(A1)"],
            $this->dataLines($out),
        );
    }

    public function testNeutralisesLeadingTabAndCarriageReturn(): void
    {
        // Tab and CR-led cells get quoted by fputcsv, so verify via round-trip parse.
        $out = $this->render([], [
            ["\tTAB"],
            ["\rCR"],
        ], bom: false);

        $lines = $this->dataLines($out);
        self::assertSame(["'\tTAB"], str_getcsv($lines[0], ',', '"', ''));
        // A CR embedded in the field means str_getcsv on a single line is awkward;
        // assert the raw neutralised, quoted form instead.
        self::assertStringContainsString("'\r", $out);
    }

    public function testNumericAndBooleanCellsPassThroughUntouched(): void
    {
        // The int -1200 must stay numeric even though it starts with '-'.
        $out = $this->render([], [
            [-1200, -1.5, true, false, null],
        ], bom: false);

        self::assertSame(['-1200,-1.5,1,,'], $this->dataLines($out));
    }

    public function testStringNegativeIsNeutralisedButNumericNegativeIsNot(): void
    {
        // The discriminating case: same textual "-1200", different type => different output.
        $out = $this->render([], [
            ['-1200', -1200],
        ], bom: false);

        self::assertSame(["'-1200,-1200"], $this->dataLines($out));
    }

    public function testRfc4180QuotingForCommaNewlineAndQuote(): void
    {
        $out = $this->render([], [
            ['a,b'],
            ['a"b'],
            ["a\nb"],
        ], bom: false);

        // Comma-bearing cell is enclosed; embedded quote is doubled (not backslash-escaped).
        self::assertStringContainsString('"a,b"', $out);
        self::assertStringContainsString('"a""b"', $out);
        self::assertStringNotContainsString('\\"', $out);

        // Round-trips back to the original values under RFC 4180 parsing.
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $out);
        rewind($stream);

        $parsed = [];
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $parsed[] = $record;
        }

        self::assertSame([['a,b'], ['a"b'], ["a\nb"]], $parsed);
    }

    public function testEmitsBomByDefaultAndWritesHeaderRow(): void
    {
        $out = $this->render(['id', 'name'], [
            [1, 'Alice'],
        ]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $out);
        self::assertSame(["\xEF\xBB\xBFid,name", '1,Alice'], $this->dataLines($out));
    }

    public function testOmitsBomWhenDisabled(): void
    {
        $out = $this->render(['id'], [[1]], bom: false);

        self::assertStringStartsNotWith("\xEF\xBB\xBF", $out);
        self::assertSame(['id', '1'], $this->dataLines($out));
    }

    public function testEmptyExportStillEmitsBomAndHeader(): void
    {
        $out = $this->render(['id', 'name'], []);

        self::assertSame("\xEF\xBB\xBFid,name\n", $out);
    }

    public function testWriteAllStreamsFromAGenerator(): void
    {
        $generator = (static function (): \Generator {
            yield ['=danger'];
            yield [2, 'two'];
            yield [3, 'three'];
        })();

        $out = $this->render(['a', 'b'], $generator, bom: false);

        self::assertSame(
            ['a,b', "'=danger", '2,two', '3,three'],
            $this->dataLines($out),
        );
    }

    public function testWriteRowAppendsIncrementally(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $writer = new CsvWriter($stream, ['h'], bom: false);
        $writer->writeRow(['=x']);
        $writer->writeRow([42]);

        rewind($stream);
        $out = stream_get_contents($stream) ?: '';

        self::assertSame(['h', "'=x", '42'], $this->dataLines($out));
    }

    public function testSanitizeCanBeDisabledForTrustedData(): void
    {
        $out = $this->render([], [['=SUM(A1:A2)']], bom: false, sanitize: false);

        self::assertSame(['=SUM(A1:A2)'], $this->dataLines($out));
    }
}
