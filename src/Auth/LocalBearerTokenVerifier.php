<?php

declare(strict_types=1);

namespace Nene2\Auth;

/**
 * HMAC-HS256 JWT verifier for local development.
 * Uses no external library — suitable only for controlled local environments.
 * Production deployments should inject a library-backed TokenVerifierInterface.
 */
final readonly class LocalBearerTokenVerifier implements TokenVerifierInterface
{
    public function __construct(private string $secret)
    {
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

        if (isset($claims['exp']) && is_int($claims['exp']) && $claims['exp'] < time()) {
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
