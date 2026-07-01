<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\TotpAuthenticator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TotpAuthenticatorTest extends TestCase
{
    // ------------------------------------------------------------------ construction

    #[Test]
    public function rejectsDigitsBelowSix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpAuthenticator(digits: 5);
    }

    #[Test]
    public function rejectsDigitsAboveEight(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpAuthenticator(digits: 9);
    }

    #[Test]
    public function rejectsNonPositivePeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpAuthenticator(period: 0);
    }

    #[Test]
    public function rejectsNegativeWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpAuthenticator(window: -1);
    }

    #[Test]
    public function rejectsUnknownAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpAuthenticator(algorithm: 'md5');
    }

    // ------------------------------------------------------------------ generateSecret

    #[Test]
    public function generateSecretReturnsBase32String(): void
    {
        $secret = (new TotpAuthenticator())->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    #[Test]
    public function generateSecretDefaultIs32Chars(): void
    {
        // 20 bytes → 160 bits → 32 Base32 chars (no padding)
        self::assertSame(32, strlen((new TotpAuthenticator())->generateSecret()));
    }

    #[Test]
    public function generateSecretIsUnique(): void
    {
        $totp = new TotpAuthenticator();

        self::assertNotSame($totp->generateSecret(), $totp->generateSecret());
    }

    #[Test]
    public function generateSecretRejectsZeroBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TotpAuthenticator())->generateSecret(0);
    }

    // ------------------------------------------------------------------ computeCode (RFC 6238 vectors)

    /**
     * RFC 6238 Appendix B reference: the ASCII secret "12345678901234567890"
     * with SHA-1 and 8 digits yields known codes at fixed timestamps.
     * "12345678901234567890" Base32-encodes to "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ".
     */
    #[Test]
    public function computeCodeMatchesRfc6238ReferenceVector(): void
    {
        $totp = new TotpAuthenticator(digits: 8);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // T=59 → time step 1, expected code 94287082 (RFC 6238 Appendix B table).
        $step = $totp->currentTimeStep(59);

        self::assertSame(1, $step);
        self::assertSame('94287082', $totp->computeCode($secret, $step));
    }

    #[Test]
    public function computeCodeMatchesSecondRfc6238Vector(): void
    {
        $totp = new TotpAuthenticator(digits: 8);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // T=1111111109 → expected code 07081804 (RFC 6238 Appendix B).
        self::assertSame('07081804', $totp->computeCode($secret, $totp->currentTimeStep(1111111109)));
    }

    #[Test]
    public function computeCodeHasConfiguredDigitLength(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();

        self::assertSame(6, strlen($totp->computeCode($secret, 42)));
    }

    #[Test]
    public function computeCodeIsDeterministicForSameStep(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();

        self::assertSame(
            $totp->computeCode($secret, 100),
            $totp->computeCode($secret, 100),
        );
    }

    // ------------------------------------------------------------------ currentTimeStep

    #[Test]
    public function currentTimeStepDividesByPeriod(): void
    {
        $totp = new TotpAuthenticator(period: 30);

        self::assertSame(3, $totp->currentTimeStep(90));
        self::assertSame(3, $totp->currentTimeStep(119));
        self::assertSame(4, $totp->currentTimeStep(120));
    }

    // ------------------------------------------------------------------ verify

    #[Test]
    public function verifyAcceptsCurrentCodeAndReturnsStep(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $step = $totp->currentTimeStep($now);
        $code = $totp->computeCode($secret, $step);

        self::assertSame($step, $totp->verify($secret, $code, $now));
    }

    #[Test]
    public function verifyAcceptsPreviousStepWithinWindow(): void
    {
        $totp = new TotpAuthenticator(window: 1);
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $previousStep = $totp->currentTimeStep($now) - 1;
        $code = $totp->computeCode($secret, $previousStep);

        self::assertSame($previousStep, $totp->verify($secret, $code, $now));
    }

    #[Test]
    public function verifyRejectsCodeOutsideWindow(): void
    {
        $totp = new TotpAuthenticator(window: 1);
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        // Two steps ahead is outside a window of 1.
        $code = $totp->computeCode($secret, $totp->currentTimeStep($now) + 2);

        self::assertNull($totp->verify($secret, $code, $now));
    }

    #[Test]
    public function verifyWithZeroWindowRejectsAdjacentStep(): void
    {
        $totp = new TotpAuthenticator(window: 0);
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $code = $totp->computeCode($secret, $totp->currentTimeStep($now) - 1);

        self::assertNull($totp->verify($secret, $code, $now));
    }

    #[Test]
    public function verifyRejectsWrongCode(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();

        self::assertNull($totp->verify($secret, '000000', 1_700_000_000));
    }

    #[Test]
    public function verifyRejectsMalformedCode(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();

        self::assertNull($totp->verify($secret, 'abcdef', 1_700_000_000));
        self::assertNull($totp->verify($secret, '12345', 1_700_000_000));  // too short
        self::assertNull($totp->verify($secret, '1234567', 1_700_000_000)); // too long
    }

    #[Test]
    public function verifyRejectsEmptyInputs(): void
    {
        $totp = new TotpAuthenticator();

        self::assertNull($totp->verify('', '123456', 1_700_000_000));
        self::assertNull($totp->verify($totp->generateSecret(), '', 1_700_000_000));
    }

    #[Test]
    public function verifyTrimsSurroundingWhitespace(): void
    {
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $code = $totp->computeCode($secret, $totp->currentTimeStep($now));

        self::assertSame($totp->currentTimeStep($now), $totp->verify($secret, "  {$code}  ", $now));
    }

    // ------------------------------------------------------------------ provisioningUri

    #[Test]
    public function provisioningUriHasExpectedShape(): void
    {
        $totp = new TotpAuthenticator();
        $uri = $totp->provisioningUri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'NENE2');

        self::assertStringStartsWith('otpauth://totp/NENE2:alice%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=NENE2', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    #[Test]
    public function provisioningUriReflectsCustomConfiguration(): void
    {
        $totp = new TotpAuthenticator(digits: 8, period: 60, algorithm: 'sha256');
        $uri = $totp->provisioningUri('JBSWY3DPEHPK3PXP', 'bob', 'Acme');

        self::assertStringContainsString('algorithm=SHA256', $uri);
        self::assertStringContainsString('digits=8', $uri);
        self::assertStringContainsString('period=60', $uri);
    }
}
