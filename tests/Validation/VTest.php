<?php

declare(strict_types=1);

namespace Nene2\Tests\Validation;

use Nene2\Validation\V;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backed enum used only in this test file to exercise V::enum().
 *
 * @internal
 */
enum TestRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Admin  = 'admin';
}

/**
 * Unit tests for V — stateless HTTP parameter validation helpers.
 *
 * Covers the VULN patterns identified in FT175–FT177:
 *   - Type confusion (string "2" vs int 2, bool, null, float)
 *   - Integer overflow (20-digit strings)
 *   - ReDoS immunity (ctype_digit instead of regex)
 *   - Padding / signed integers (+10, space)
 *   - Float strings (10.5, 1e2)
 *   - Hex / octal notation (0x10, 010)
 *   - ISO date overflow (Feb 30)
 *   - Empty / unconfigured secret bypass
 */
final class VTest extends TestCase
{
    // ── V::queryInt ──────────────────────────────────────────────────────────

    /** @return array<string, mixed[]> */
    public static function queryIntAbsentProvider(): array
    {
        return [
            'no default returns null'   => [[], 'limit', 1, 100, null, null],
            'with default returns it'   => [[], 'limit', 1, 100, 20, 20],
            'zero default is returned'  => [[], 'page', 0, PHP_INT_MAX, 0, 0],
        ];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('queryIntAbsentProvider')]
    public function testQueryIntAbsentKey(
        array $params,
        string $key,
        int $min,
        int $max,
        ?int $default,
        ?int $expected,
    ): void {
        self::assertSame($expected, V::queryInt($params, $key, $min, $max, $default));
    }

    /** @return array<string, array{string, int, int, int}> */
    public static function queryIntValidProvider(): array
    {
        return [
            'minimum boundary'    => ['1', 1, 100, 1],
            'maximum boundary'    => ['100', 1, 100, 100],
            'mid value'           => ['50', 1, 100, 50],
            'zero when min=0'     => ['0', 0, 100, 0],
            'octal-like 010'      => ['010', 1, 100, 10],   // ctype_digit passes; (int) = 10 decimal (safe)
        ];
    }

    #[DataProvider('queryIntValidProvider')]
    public function testQueryIntValidValues(string $raw, int $min, int $max, int $expected): void
    {
        self::assertSame($expected, V::queryInt(['limit' => $raw], 'limit', $min, $max));
    }

    /** @return array<string, array{mixed}> */
    public static function queryIntInvalidProvider(): array
    {
        return [
            // Non-digit strings
            'alpha string'        => ['abc'],
            'hex 0x10'            => ['0x10'],
            'sql injection'       => ['1;DROP TABLE'],
            // Sign / whitespace / float markers — all fail ctype_digit
            'negative -1'         => ['-1'],
            'positive-sign +10'   => ['+10'],
            'url-encoded space %2010' => ['%2010'],  // decoded to ' 10' by PSR-7
            'leading space'       => [' 10'],
            'trailing space'      => ['10 '],
            'float dot 10.5'      => ['10.5'],
            'float sci 1e2'       => ['1e2'],
            'float 1.0'           => ['1.0'],
            // Overflow
            '20 nines overflow'   => [str_repeat('9', 20)],
            '19 digit boundary'   => [str_repeat('9', 19)],  // > 18 chars → null
            // Empty
            'empty string'        => [''],
            // Non-string types (e.g., duplicate param parsed as array by some servers)
            'array value'         => [['5', '1000']],
            'int type'            => [5],
            'null type'           => [null],
            'bool type'           => [true],
        ];
    }

    #[DataProvider('queryIntInvalidProvider')]
    public function testQueryIntInvalidValues(mixed $raw): void
    {
        self::assertNull(V::queryInt(['limit' => $raw], 'limit', 1, 100));
    }

    public function testQueryIntOutOfRange(): void
    {
        self::assertNull(V::queryInt(['limit' => '0'], 'limit', 1, 100));     // below min
        self::assertNull(V::queryInt(['limit' => '101'], 'limit', 1, 100));   // above max
        self::assertNull(V::queryInt(['limit' => '999999'], 'limit', 1, 100));// VULN-A: oversized
    }

    public function testQueryIntReDoSImmunity(): void
    {
        // 50 ones + 'x' — exponential backtracking on /^\d+$/ but O(n) on ctype_digit
        $payload = str_repeat('1', 50) . 'x';
        $start   = microtime(true);
        $result  = V::queryInt(['limit' => $payload], 'limit', 1, 100);
        $elapsed = microtime(true) - $start;

        self::assertNull($result);
        self::assertLessThan(0.1, $elapsed, 'VULN-L: ReDoS guard must complete in < 100ms');
    }

    // ── V::bodyInt ───────────────────────────────────────────────────────────

    public function testBodyIntAcceptsValidInt(): void
    {
        self::assertSame(1, V::bodyInt(1, 1, 100));
        self::assertSame(100, V::bodyInt(100, 1, 100));
        self::assertSame(50, V::bodyInt(50, 1, 100));
    }

    public function testBodyIntRejectsStringInt(): void
    {
        // ATK-07: type confusion — string "2" must not pass strict int check
        self::assertNull(V::bodyInt('2', 1, 100));
        self::assertNull(V::bodyInt('1', 1, 100));
    }

    public function testBodyIntRejectsFloat(): void
    {
        // 2.5 is a true float; 2.0 encodes as 2 in JSON so cannot be tested here
        self::assertNull(V::bodyInt(2.5, 1, 100));
        self::assertNull(V::bodyInt(1.5, 1, 100));
    }

    public function testBodyIntRejectsNullAndBool(): void
    {
        self::assertNull(V::bodyInt(null, 1, 100));
        self::assertNull(V::bodyInt(true, 1, 100));
        self::assertNull(V::bodyInt(false, 1, 100));
    }

    public function testBodyIntRejectsArray(): void
    {
        self::assertNull(V::bodyInt([1, 2], 1, 100));
    }

    public function testBodyIntOutOfRange(): void
    {
        self::assertNull(V::bodyInt(0, 1, 100));
        self::assertNull(V::bodyInt(101, 1, 100));
        self::assertNull(V::bodyInt(-1, 1, 100));
        self::assertNull(V::bodyInt(PHP_INT_MAX, 1, 100));
    }

    // ── V::str ───────────────────────────────────────────────────────────────

    public function testStrAcceptsNormalString(): void
    {
        self::assertSame('hello', V::str('hello'));
        self::assertSame('', V::str(''));
    }

    public function testStrTrims(): void
    {
        self::assertSame('hello', V::str('  hello  '));
        self::assertSame('', V::str('   '));
    }

    public function testStrRejectsOverLength(): void
    {
        self::assertNull(V::str(str_repeat('a', 501)));
        self::assertNull(V::str(str_repeat('a', 501), 500));
        self::assertSame(str_repeat('a', 500), V::str(str_repeat('a', 500)));  // exact max ok
    }

    public function testStrCustomMaxLen(): void
    {
        self::assertSame('abc', V::str('abc', 10));
        self::assertNull(V::str('abcdefghijk', 10));  // 11 chars, max 10
    }

    public function testStrRejectsNonString(): void
    {
        self::assertNull(V::str(42));
        self::assertNull(V::str(null));
        self::assertNull(V::str(true));
        self::assertNull(V::str(['a', 'b']));
    }

    public function testStrPreservesUnicode(): void
    {
        // Unicode / BIDI characters must be stored verbatim, not normalised
        $bidi = "\u{202E}evitcepsreP"; // right-to-left override
        self::assertSame($bidi, V::str($bidi));
    }

    // ── V::isoDatetime ───────────────────────────────────────────────────────

    public function testIsoDatetimeAcceptsValidFormat(): void
    {
        self::assertSame('2024-01-15T12:30:00+09:00', V::isoDatetime('2024-01-15T12:30:00+09:00'));
        self::assertSame('2024-06-01T00:00:00+00:00', V::isoDatetime('2024-06-01T00:00:00+00:00'));
        self::assertSame('2024-12-31T23:59:59-05:00', V::isoDatetime('2024-12-31T23:59:59-05:00'));
    }

    public function testIsoDatetimeRejectsDateOnly(): void
    {
        self::assertNull(V::isoDatetime('2024-01-15'));
    }

    public function testIsoDatetimeRejectsZSuffix(): void
    {
        self::assertNull(V::isoDatetime('2024-01-15T12:00:00Z'));
    }

    public function testIsoDatetimeRejectsMissingOffset(): void
    {
        self::assertNull(V::isoDatetime('2024-01-15T12:00:00'));
    }

    public function testIsoDatetimeRejectsOverflowDate(): void
    {
        // Feb 30 does not exist — strtotime rolls over to Mar 1; re-format catches it
        self::assertNull(V::isoDatetime('2024-02-30T00:00:00+00:00'));
        // Month 13 does not exist
        self::assertNull(V::isoDatetime('2024-13-01T00:00:00+00:00'));
    }

    public function testIsoDatetimeRejectsNonString(): void
    {
        self::assertNull(V::isoDatetime(42));
        self::assertNull(V::isoDatetime(null));
        self::assertNull(V::isoDatetime([]));
    }

    // ── V::futureDatetime ────────────────────────────────────────────────────

    public function testFutureDatetimeAcceptsFuture(): void
    {
        $now    = '2024-01-01T00:00:00+00:00';
        $future = '2024-12-31T23:59:59+00:00';

        self::assertSame($future, V::futureDatetime($future, $now));
    }

    public function testFutureDatetimeRejectsPast(): void
    {
        $now  = '2024-12-31T23:59:59+00:00';
        $past = '2024-01-01T00:00:00+00:00';

        self::assertNull(V::futureDatetime($past, $now));
    }

    public function testFutureDatetimeRejectsEqualToNow(): void
    {
        $now = '2024-06-01T12:00:00+00:00';

        self::assertNull(V::futureDatetime($now, $now));
    }

    public function testFutureDatetimeRejectsInvalidFormat(): void
    {
        self::assertNull(V::futureDatetime('2024-01-15', '2024-01-01T00:00:00+00:00'));
        self::assertNull(V::futureDatetime(null, '2024-01-01T00:00:00+00:00'));
    }

    // ── V::enum ──────────────────────────────────────────────────────────────

    public function testEnumAcceptsValidCase(): void
    {
        self::assertSame(TestRole::Viewer, V::enum('viewer', TestRole::class));
        self::assertSame(TestRole::Editor, V::enum('editor', TestRole::class));
        self::assertSame(TestRole::Admin, V::enum('admin', TestRole::class));
    }

    public function testEnumRejectsInvalidCase(): void
    {
        self::assertNull(V::enum('superuser', TestRole::class));
        self::assertNull(V::enum('', TestRole::class));
        self::assertNull(V::enum('ADMIN', TestRole::class));  // case-sensitive
    }

    public function testEnumRejectsNonString(): void
    {
        // ATK-07 style: int, null, bool must not match a string-backed enum
        self::assertNull(V::enum(0, TestRole::class));
        self::assertNull(V::enum(null, TestRole::class));
        self::assertNull(V::enum(true, TestRole::class));
        self::assertNull(V::enum(['admin'], TestRole::class));
    }

    // ── V::userId ────────────────────────────────────────────────────────────

    public function testUserIdAcceptsPositiveInt(): void
    {
        self::assertSame(1, V::userId('1'));
        self::assertSame(42, V::userId('42'));
        self::assertSame(999, V::userId('999'));
    }

    public function testUserIdRejectsZero(): void
    {
        self::assertNull(V::userId('0'));
    }

    public function testUserIdRejectsEmpty(): void
    {
        // ctype_digit('') === false — already rejected
        self::assertNull(V::userId(''));
    }

    public function testUserIdRejectsNegativeAndSigned(): void
    {
        self::assertNull(V::userId('-1'));
        self::assertNull(V::userId('+1'));
    }

    public function testUserIdRejectsNonDigit(): void
    {
        self::assertNull(V::userId('abc'));
        self::assertNull(V::userId('1.5'));
        self::assertNull(V::userId('0x01'));
    }

    public function testUserIdRejectsOverflow(): void
    {
        // 19 chars > 18 — overflow guard
        self::assertNull(V::userId(str_repeat('9', 19)));
    }

    // ── V::secret ────────────────────────────────────────────────────────────

    public function testSecretMatchesCorrectKey(): void
    {
        self::assertTrue(V::secret('super-secret-key', 'super-secret-key'));
    }

    public function testSecretRejectsMismatch(): void
    {
        self::assertFalse(V::secret('correct', 'wrong'));
    }

    public function testSecretRejectsEmptyExpected(): void
    {
        // Unconfigured key must never grant access — not even with empty actual
        self::assertFalse(V::secret('', ''));
        self::assertFalse(V::secret('', 'any-value'));
    }

    public function testSecretRejectsEmptyActual(): void
    {
        self::assertFalse(V::secret('expected-key', ''));
    }
}
