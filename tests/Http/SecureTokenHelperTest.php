<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\SecureTokenHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecureTokenHelperTest extends TestCase
{
    // ------------------------------------------------------------------ generate

    #[Test]
    public function generateReturnsHexString(): void
    {
        $token = SecureTokenHelper::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    #[Test]
    public function generateDefaultIs64Chars(): void
    {
        // 32 bytes → 64 hex chars
        self::assertSame(64, strlen(SecureTokenHelper::generate()));
    }

    #[Test]
    public function generateWithCustomBytes(): void
    {
        self::assertSame(32, strlen(SecureTokenHelper::generate(16)));
    }

    #[Test]
    public function generateIsUnique(): void
    {
        $a = SecureTokenHelper::generate();
        $b = SecureTokenHelper::generate();

        self::assertNotSame($a, $b);
    }

    // ------------------------------------------------------------------ hash

    #[Test]
    public function hashReturnsSha256HexString(): void
    {
        $hash = SecureTokenHelper::hash('sometoken');

        // SHA-256 always produces 64 hex chars
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function hashIsDeterministic(): void
    {
        self::assertSame(
            SecureTokenHelper::hash('abc'),
            SecureTokenHelper::hash('abc'),
        );
    }

    #[Test]
    public function differentInputsProduceDifferentHashes(): void
    {
        self::assertNotSame(
            SecureTokenHelper::hash('token-a'),
            SecureTokenHelper::hash('token-b'),
        );
    }

    // ------------------------------------------------------------------ verify

    #[Test]
    public function verifyReturnsTrueForMatchingToken(): void
    {
        $raw  = SecureTokenHelper::generate();
        $hash = SecureTokenHelper::hash($raw);

        self::assertTrue(SecureTokenHelper::verify($raw, $hash));
    }

    #[Test]
    public function verifyReturnsFalseForWrongToken(): void
    {
        $hash = SecureTokenHelper::hash('correct-token');

        self::assertFalse(SecureTokenHelper::verify('wrong-token', $hash));
    }

    #[Test]
    public function verifyReturnsFalseForEmptyRawToken(): void
    {
        $hash = SecureTokenHelper::hash('sometoken');

        self::assertFalse(SecureTokenHelper::verify('', $hash));
    }

    #[Test]
    public function verifyReturnsFalseForEmptyStoredHash(): void
    {
        self::assertFalse(SecureTokenHelper::verify('sometoken', ''));
    }

    #[Test]
    public function verifyReturnsFalseForBothEmpty(): void
    {
        self::assertFalse(SecureTokenHelper::verify('', ''));
    }

    // ------------------------------------------------------------------ generateWithHash

    #[Test]
    public function generateWithHashReturnsTwoStrings(): void
    {
        [$raw, $hash] = SecureTokenHelper::generateWithHash();

        self::assertNotEmpty($raw);
        self::assertNotEmpty($hash);
    }

    #[Test]
    public function generateWithHashRawAndHashAreConsistent(): void
    {
        [$raw, $hash] = SecureTokenHelper::generateWithHash();

        self::assertTrue(SecureTokenHelper::verify($raw, $hash));
    }

    #[Test]
    public function generateWithHashDefaultRawIs64Chars(): void
    {
        [$raw] = SecureTokenHelper::generateWithHash();

        self::assertSame(64, strlen($raw));
    }

    #[Test]
    public function generateWithHashCustomBytes(): void
    {
        [$raw] = SecureTokenHelper::generateWithHash(16);

        self::assertSame(32, strlen($raw));
    }
}
