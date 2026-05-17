<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Auth\TokenVerificationException;
use PHPUnit\Framework\TestCase;

final class LocalBearerTokenVerifierTest extends TestCase
{
    private const string SECRET = 'test-secret-key-at-least-32-chars!!';

    public function testIssueAndVerifyRoundTrip(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
        $claims = ['sub' => 'user-1', 'scope' => 'read:system'];

        $token = $verifier->issue($claims);
        $decoded = $verifier->verify($token);

        self::assertSame('user-1', $decoded['sub']);
        self::assertSame('read:system', $decoded['scope']);
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $issuer = new LocalBearerTokenVerifier(self::SECRET);
        $verifier = new LocalBearerTokenVerifier('different-secret-that-is-also-long!!');

        $token = $issuer->issue(['sub' => 'user-1']);

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
}
