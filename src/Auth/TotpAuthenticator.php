<?php

declare(strict_types=1);

namespace Nene2\Auth;

/**
 * RFC 6238 Time-based One-Time Password (TOTP) primitive.
 *
 * This class provides the security-critical parts of TOTP two-factor
 * authentication so that each application does not re-implement the RFC 6238
 * algorithm, Base32 secret handling, or the constant-time code comparison. It
 * is generic and application-independent, following the same split as
 * {@see LocalBearerTokenVerifier} and `SecureTokenHelper`: the framework owns
 * the dangerous cryptography, the application owns the HTTP flow and policy.
 *
 * What this class does:
 * - generate a Base32 shared secret ({@see generateSecret()});
 * - build an `otpauth://` provisioning URI for authenticator apps
 *   ({@see provisioningUri()});
 * - compute a code for a specific time step ({@see computeCode()});
 * - verify a submitted code within a tolerance window, in constant time,
 *   returning the matched time step for replay tracking ({@see verify()}).
 *
 * What the application still owns (intentionally out of scope):
 * - the enroll / challenge HTTP endpoints and any UI;
 * - the enforcement policy (who must use TOTP, when it is required);
 * - secret storage and at-rest encryption in the data layer;
 * - replay prevention — persist the matched time step returned by
 *   {@see verify()} and reject a step that was already consumed;
 * - brute-force lockout.
 *
 * ```php
 * $totp = new TotpAuthenticator();
 *
 * // Enrollment: generate a secret and show it to the user.
 * $secret = $totp->generateSecret();
 * $uri    = $totp->provisioningUri($secret, 'alice@example.com', 'NENE2');
 * // → render $uri as a QR code; persist $secret for the user (encrypted).
 *
 * // Verification (login / enable): validate a 6-digit code.
 * $matchedStep = $totp->verify($secret, $submittedCode);
 * if ($matchedStep === null) {
 *     // invalid or expired code
 * } elseif ($repo->isStepUsed($userId, $matchedStep)) {
 *     // replay: this code was already used
 * } else {
 *     $repo->markStepUsed($userId, $matchedStep);
 *     // authenticated
 * }
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class TotpAuthenticator
{
    /** RFC 4648 Base32 alphabet (no padding). */
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private int $digits;
    private int $period;
    private string $algorithm;
    private int $window;

    /**
     * @param int    $digits    Number of code digits (6, 7, or 8). Authenticator apps use 6.
     * @param int    $period    Time step length in seconds (RFC 6238 default: 30).
     * @param string $algorithm HMAC hash algorithm: `sha1` (default, widest app support),
     *                          `sha256`, or `sha512`.
     * @param int    $window    Number of adjacent time steps accepted on each side of the
     *                          current step to tolerate clock skew (default: 1 → ±`$period` s).
     *                          Keep small; a wider window weakens security.
     *
     * @throws \InvalidArgumentException When any configuration value is out of range.
     */
    public function __construct(int $digits = 6, int $period = 30, string $algorithm = 'sha1', int $window = 1)
    {
        if ($digits < 6 || $digits > 8) {
            throw new \InvalidArgumentException('TOTP digit count must be between 6 and 8.');
        }

        if ($period < 1) {
            throw new \InvalidArgumentException('TOTP period must be at least 1 second.');
        }

        if ($window < 0) {
            throw new \InvalidArgumentException('TOTP verification window must not be negative.');
        }

        $normalizedAlgorithm = strtolower($algorithm);

        if (!in_array($normalizedAlgorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new \InvalidArgumentException('TOTP algorithm must be one of sha1, sha256, or sha512.');
        }

        $this->digits = $digits;
        $this->period = $period;
        $this->algorithm = $normalizedAlgorithm;
        $this->window = $window;
    }

    /**
     * Generates a new Base32-encoded shared secret using a CSPRNG.
     *
     * The default of 20 bytes is the RFC 6238 recommended secret size for SHA-1
     * (160 bits) and encodes to 32 Base32 characters with no padding.
     *
     * ```php
     * $secret = $totp->generateSecret(); // e.g. "JBSWY3DPEHPK3PXP..." (32 chars)
     * ```
     *
     * @param int $bytes Number of random bytes. Must be at least 1.
     *
     * @throws \InvalidArgumentException When `$bytes` is less than 1.
     */
    public function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Secret byte length must be at least 1.');
        }

        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * Builds an `otpauth://totp/` provisioning URI for authenticator apps.
     *
     * Render the returned URI as a QR code. The parameters (algorithm, digits,
     * period) reflect this authenticator's configuration so the app computes
     * matching codes.
     *
     * ```php
     * $uri = $totp->provisioningUri($secret, 'alice@example.com', 'NENE2');
     * // otpauth://totp/NENE2:alice%40example.com?secret=...&issuer=NENE2&algorithm=SHA1&digits=6&period=30
     * ```
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Returns the current time step for a given (or the current) Unix timestamp.
     *
     * Pass `$now` in tests to make code generation deterministic.
     */
    public function currentTimeStep(?int $now = null): int
    {
        return intdiv($now ?? time(), $this->period);
    }

    /**
     * Computes the TOTP code for a specific time step.
     *
     * Useful for tests and for the enable flow, where the caller needs a code
     * for the current or an adjacent step. See {@see currentTimeStep()}.
     *
     * ```php
     * $code = $totp->computeCode($secret, $totp->currentTimeStep());
     * ```
     */
    public function computeCode(string $secret, int $timeStep): string
    {
        $key = $this->base32Decode($secret);
        $counter = pack('J', $timeStep); // 8-byte big-endian counter (RFC 4226 §5.3)
        $hash = hash_hmac($this->algorithm, $counter, $key, true);

        // Dynamic truncation (RFC 4226 §5.4): low 4 bits of the last byte give the offset.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (int) (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a submitted code against the secret within the tolerance window.
     *
     * Returns the matched time step on success, or `null` when the code is
     * invalid, malformed, or outside the window. Comparison uses `hash_equals()`
     * for constant-time behavior, preventing timing-oracle attacks.
     *
     * The returned step is the caller's replay key: persist it and reject a
     * step that was already consumed (a single code stays valid for one period).
     *
     * ```php
     * $step = $totp->verify($secret, $code);
     * if ($step === null || $repo->isStepUsed($userId, $step)) {
     *     return $unauthorized;
     * }
     * $repo->markStepUsed($userId, $step);
     * ```
     *
     * @param int|null $now Optional Unix timestamp for deterministic tests.
     */
    public function verify(string $secret, string $code, ?int $now = null): ?int
    {
        $code = trim($code);

        if ($secret === '' || strlen($code) !== $this->digits || !ctype_digit($code)) {
            return null;
        }

        $currentStep = $this->currentTimeStep($now);

        for ($offset = -$this->window; $offset <= $this->window; $offset++) {
            $step = $currentStep + $offset;

            if (hash_equals($this->computeCode($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Encodes raw bytes to an unpadded RFC 4648 Base32 string.
     */
    private function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[(int) bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Decodes an RFC 4648 Base32 string to raw bytes.
     *
     * Case-insensitive; ignores whitespace, padding, and any non-alphabet
     * characters. Trailing bits that do not form a full byte are discarded.
     */
    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $base32));

        if ($base32 === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
