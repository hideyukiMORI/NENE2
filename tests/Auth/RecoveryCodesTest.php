<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\RecoveryCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    // ------------------------------------------------------------------ generate

    #[Test]
    public function generateReturnsRequestedCount(): void
    {
        self::assertCount(10, RecoveryCodes::generate());
        self::assertCount(3, RecoveryCodes::generate(3));
    }

    #[Test]
    public function generateReturnsFormattedCodes(): void
    {
        foreach (RecoveryCodes::generate() as $code) {
            // default 5 bytes → 10 hex chars grouped as "xxxxx-xxxxx"
            self::assertMatchesRegularExpression('/^[0-9a-f]{5}-[0-9a-f]{5}$/', $code);
        }
    }

    #[Test]
    public function generateReturnsUniqueCodes(): void
    {
        $codes = RecoveryCodes::generate();

        self::assertSame($codes, array_values(array_unique($codes)));
    }

    #[Test]
    public function generateRespectsCustomByteLength(): void
    {
        foreach (RecoveryCodes::generate(2, 8) as $code) {
            // 8 bytes → 16 hex chars grouped by 5 → "xxxxx-xxxxx-xxxxx-x"
            self::assertSame(16, strlen(str_replace('-', '', $code)));
        }
    }

    #[Test]
    public function generateRejectsNonPositiveCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecoveryCodes::generate(0);
    }

    #[Test]
    public function generateRejectsNonPositiveByteLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecoveryCodes::generate(10, 0);
    }

    // ------------------------------------------------------------------ hash / verify

    #[Test]
    public function hashReturnsSha256HexString(): void
    {
        $hash = RecoveryCodes::hash('abcde-12345');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function verifyAcceptsMatchingCode(): void
    {
        $code = RecoveryCodes::generate()[0];

        self::assertTrue(RecoveryCodes::verify($code, RecoveryCodes::hash($code)));
    }

    #[Test]
    public function verifyRejectsWrongCode(): void
    {
        $hash = RecoveryCodes::hash('abcde-12345');

        self::assertFalse(RecoveryCodes::verify('fffff-00000', $hash));
    }

    #[Test]
    public function verifyIgnoresFormattingAndCase(): void
    {
        $hash = RecoveryCodes::hash('abcde-12345');

        self::assertTrue(RecoveryCodes::verify('ABCDE12345', $hash));
        self::assertTrue(RecoveryCodes::verify('  abcde-12345  ', $hash));
        self::assertTrue(RecoveryCodes::verify('ab cd e1 23 45', $hash));
    }

    #[Test]
    public function verifyRejectsEmptyInputs(): void
    {
        self::assertFalse(RecoveryCodes::verify('', RecoveryCodes::hash('abcde-12345')));
        self::assertFalse(RecoveryCodes::verify('abcde-12345', ''));
    }

    // ------------------------------------------------------------------ normalize

    #[Test]
    public function normalizeStripsSeparatorsAndLowercases(): void
    {
        self::assertSame('abcde12345', RecoveryCodes::normalize('ABCDE-12345'));
        self::assertSame('abcde12345', RecoveryCodes::normalize('  ab cd-e1 2345 '));
    }
}
