<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use DateTimeImmutable;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Auth\TokenVerificationException;
use Nene2\Http\ClockInterface;
use PHPUnit\Framework\TestCase;

final class LocalBearerTokenVerifierTest extends TestCase
{
    private const string SECRET = 'test-secret-key-at-least-32-chars!!';

    public function testIssueAndVerifyRoundTrip(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $claims = ['sub' => 'user-1', 'exp' => time() + 3600, 'scope' => 'read:system'];

        $token = $verifier->issue($claims);
        $decoded = $verifier->verify($token);

        self::assertSame('user-1', $decoded['sub']);
        self::assertSame('read:system', $decoded['scope']);
    }

    public function testVerifyRejectsTokenWithoutExp(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1']);

        $this->expectException(TokenVerificationException::class);
        $this->expectExceptionMessage('numeric exp claim');
        $verifier->verify($token);
    }

    public function testVerifyRejectsTokenWithNonIntegerExp(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => 'never']);

        $this->expectException(TokenVerificationException::class);
        $this->expectExceptionMessage('numeric exp claim');
        $verifier->verify($token);
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $issuer = new LocalBearerTokenVerifier(self::SECRET);
        $verifier = new LocalBearerTokenVerifier('different-secret-that-is-also-long!!');

        $token = $issuer->issue(['sub' => 'user-1', 'exp' => time() + 3600]);

        $this->expectException(TokenVerificationException::class);
        $verifier->verify($token);
    }

    public function testVerifyRejectsMalformedToken(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);

        $this->expectException(TokenVerificationException::class);
        $verifier->verify('not.a.valid.jwt.format.here');
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => time() - 60]);

        $this->expectException(TokenVerificationException::class);
        $verifier->verify($token);
    }

    public function testVerifyAcceptsTokenWithFutureExpiry(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => time() + 3600]);

        $claims = $verifier->verify($token);

        self::assertSame('user-1', $claims['sub']);
    }

    public function testVerifyRejectsWrongAlgorithm(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);

        $headerB64 = rtrim(strtr(base64_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256'])), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode((string) json_encode(['sub' => 'x'])), '+/', '-_'), '=');
        $sigB64 = rtrim(strtr(base64_encode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, self::SECRET, true)), '+/', '-_'), '=');
        $token = $headerB64 . '.' . $payloadB64 . '.' . $sigB64;

        $this->expectException(TokenVerificationException::class);
        $verifier->verify($token);
    }

    public function testVerifyRejectsTokenNotYetValid(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => time() + 3600, 'nbf' => time() + 600]);

        $this->expectException(TokenVerificationException::class);
        $this->expectExceptionMessage('not yet valid');
        $verifier->verify($token);
    }

    public function testVerifyAcceptsTokenWithPastNbf(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => time() + 3600, 'nbf' => time() - 60]);

        $claims = $verifier->verify($token);

        self::assertSame('user-1', $claims['sub']);
    }

    public function testVerifyIgnoresNonIntegerNbf(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $token = $verifier->issue(['sub' => 'user-1', 'exp' => time() + 3600, 'nbf' => 'not-an-int']);

        $claims = $verifier->verify($token);

        self::assertSame('user-1', $claims['sub']);
    }

    public function testInjectedFixedClockDrivesExpiryDeterministically(): void
    {
        // A token whose exp is far in the past relative to the real wall clock,
        // but valid relative to a fixed clock pinned before that exp. Proves the
        // decision no longer depends on the system clock.
        $fixedNow = new DateTimeImmutable('2000-01-01T00:00:00Z');
        $verifier = new LocalBearerTokenVerifier(self::SECRET, $this->clockAt($fixedNow));

        $token = $verifier->issue([
            'sub' => 'user-1',
            'exp' => $fixedNow->getTimestamp() + 3600,
        ]);

        $claims = $verifier->verify($token);

        self::assertSame('user-1', $claims['sub']);
    }

    public function testInjectedFixedFutureClockRejectsExpiredTokenDeterministically(): void
    {
        // exp is in the future relative to the real wall clock, yet the fixed
        // clock is pinned even further ahead → deterministic expiry.
        $fixedNow = new DateTimeImmutable('2999-01-01T00:00:00Z');
        $verifier = new LocalBearerTokenVerifier(self::SECRET, $this->clockAt($fixedNow));

        $token = $verifier->issue([
            'sub' => 'user-1',
            'exp' => $fixedNow->getTimestamp() - 60,
        ]);

        $this->expectException(TokenVerificationException::class);
        $this->expectExceptionMessage('expired');
        $verifier->verify($token);
    }

    public function testInjectedFixedClockDrivesNbfDeterministically(): void
    {
        // nbf is one second after the pinned instant → not yet valid,
        // regardless of the real system time.
        $fixedNow = new DateTimeImmutable('2500-06-01T12:00:00Z');
        $verifier = new LocalBearerTokenVerifier(self::SECRET, $this->clockAt($fixedNow));

        $token = $verifier->issue([
            'sub' => 'user-1',
            'exp' => $fixedNow->getTimestamp() + 3600,
            'nbf' => $fixedNow->getTimestamp() + 1,
        ]);

        $this->expectException(TokenVerificationException::class);
        $this->expectExceptionMessage('not yet valid');
        $verifier->verify($token);
    }

    public function testSharedFixedClockKeepsIssueAndVerifySymmetric(): void
    {
        // Issue and verify through the same instance sharing one fixed clock:
        // exp = now + ttl computed from the same clock the verifier reads.
        $fixedNow = new DateTimeImmutable('2100-03-14T09:26:53Z');
        $clock = $this->clockAt($fixedNow);
        $verifier = new LocalBearerTokenVerifier(self::SECRET, $clock);

        $ttl = 900;
        $token = $verifier->issue([
            'sub' => 'user-1',
            'nbf' => $clock->now()->getTimestamp(),
            'exp' => $clock->now()->getTimestamp() + $ttl,
        ]);

        $claims = $verifier->verify($token);

        self::assertSame('user-1', $claims['sub']);
        self::assertSame($fixedNow->getTimestamp() + $ttl, $claims['exp']);
    }

    public function testExpiryBoundaryIsDeterministicWithFixedClock(): void
    {
        // exp exactly equal to now is still valid (strict `<` comparison);
        // exactly one second earlier is expired. Deterministic without any
        // second-boundary flakiness because "now" is read once from the clock.
        $fixedNow = new DateTimeImmutable('2200-01-01T00:00:00Z');
        $verifier = new LocalBearerTokenVerifier(self::SECRET, $this->clockAt($fixedNow));

        $atBoundary = $verifier->issue(['sub' => 'user-1', 'exp' => $fixedNow->getTimestamp()]);
        self::assertSame('user-1', $verifier->verify($atBoundary)['sub']);

        $justExpired = $verifier->issue(['sub' => 'user-1', 'exp' => $fixedNow->getTimestamp() - 1]);
        $this->expectException(TokenVerificationException::class);
        $verifier->verify($justExpired);
    }

    private function clockAt(DateTimeImmutable $instant): ClockInterface
    {
        return new class ($instant) implements ClockInterface {
            public function __construct(private DateTimeImmutable $instant)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->instant;
            }
        };
    }
}
