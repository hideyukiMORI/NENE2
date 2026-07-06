<?php

declare(strict_types=1);

namespace Nene2\Auth;

use Nene2\Http\ClockInterface;
use Nene2\Http\UtcClock;

/**
 * HMAC-HS256 JWT verifier and issuer for local development and testing.
 * Uses no external library — suitable only for controlled local environments.
 * Production deployments should inject library-backed implementations of
 * {@see TokenVerifierInterface} and {@see TokenIssuerInterface}.
 *
 * The optional {@see ClockInterface} controls the time source used for `exp`/`nbf`
 * verification. The default {@see UtcClock} returns the real UTC time, so existing
 * callers behave exactly as before. Inject a fixed clock in tests to make time-based
 * `exp`/`nbf` boundaries deterministic (and to share one time source with `issue()`).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class LocalBearerTokenVerifier implements TokenVerifierInterface, TokenIssuerInterface
{
    public function __construct(
        private string $secret,
        private ClockInterface $clock = new UtcClock(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new TokenVerificationException('Token format is invalid: expected three dot-separated segments.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header = $this->decodeJsonSegment($headerB64);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new TokenVerificationException('Token algorithm must be HS256.');
        }

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true)
        );

        if (!hash_equals($expected, $sigB64)) {
            throw new TokenVerificationException('Token signature is invalid.');
        }

        $claims = $this->decodeJsonSegment($payloadB64);

        // Read "now" once so nbf/exp are evaluated against a single instant
        // (avoids a second-boundary race between two time reads) and can be
        // pinned deterministically via an injected clock.
        $now = $this->clock->now()->getTimestamp();

        if (isset($claims['nbf']) && is_int($claims['nbf']) && $claims['nbf'] > $now) {
            throw new TokenVerificationException('Token is not yet valid.');
        }

        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            throw new TokenVerificationException('Token must contain a numeric exp claim.');
        }

        if ($claims['exp'] < $now) {
            throw new TokenVerificationException('Token has expired.');
        }

        return $claims;
    }

    /**
     * Issue a signed HS256 JWT for local testing.
     *
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims): string
    {
        $headerB64 = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $payloadB64 = $this->base64UrlEncode((string) json_encode($claims, JSON_THROW_ON_ERROR));
        $sigB64 = $this->base64UrlEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true));

        return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     */
    private function decodeJsonSegment(string $b64): array
    {
        $json = base64_decode(strtr($b64, '-_', '+/'), strict: true);

        if ($json === false) {
            throw new TokenVerificationException('Token segment has invalid base64url encoding.');
        }

        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenVerificationException('Token segment has invalid JSON.');
        }

        if (!is_array($decoded)) {
            throw new TokenVerificationException('Token segment must be a JSON object.');
        }

        return $decoded;
    }
}
