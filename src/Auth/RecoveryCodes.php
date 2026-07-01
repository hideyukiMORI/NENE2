<?php

declare(strict_types=1);

namespace Nene2\Auth;

/**
 * Generation, hashing, and constant-time verification of one-time recovery codes.
 *
 * Recovery (backup) codes let a user regain access when their TOTP device is
 * unavailable. The safe pattern is always the same and mirrors
 * `SecureTokenHelper`:
 *
 * 1. Generate a batch of high-entropy codes and show them to the user **once**.
 * 2. Store only the hash of each code — never the raw value.
 * 3. On use, verify with a timing-safe comparison and then **consume** the code
 *    (delete or flag its hash) so it cannot be reused.
 *
 * This helper owns steps 1–2 and the comparison in step 3. Persisting the
 * hashes and enforcing single use (the "consume" action, plus rate limiting)
 * stay with the application, following the same framework/app split as
 * {@see TotpAuthenticator}.
 *
 * Codes are normalized before hashing and comparison, so formatting and case
 * entered by the user (e.g. `ABCDE-12345` vs `abcde12345`) do not matter.
 *
 * ```php
 * // Enrollment: generate and display once, persist the hashes.
 * $codes = RecoveryCodes::generate();            // e.g. ["3f9ac-1b0e7-8d2f4-0a6c1", ...]
 * foreach ($codes as $code) {
 *     $repo->storeRecoveryHash($userId, RecoveryCodes::hash($code));
 * }
 * // → show $codes to the user now; they are unrecoverable afterwards.
 *
 * // Redemption: verify against each stored hash, then consume the match.
 * foreach ($repo->recoveryHashes($userId) as $stored) {
 *     if (RecoveryCodes::verify($submitted, $stored->hash)) {
 *         $repo->consumeRecoveryHash($stored->id); // single use
 *         // authenticated
 *     }
 * }
 * ```
 *
 * Security notes:
 * - `generate()` uses `random_bytes()` — a CSPRNG suitable for security tokens.
 * - `hash()` uses SHA-256 without a salt. This is safe *only* because each code
 *   carries high entropy (80 bits by default): a database breach exposes hashes
 *   whose 2^80 preimage space is infeasible to brute-force offline, even
 *   unsalted and even shared across users. Do not lower `$bytes` below ~10
 *   (80 bits) — a low-entropy code space (e.g. 40 bits) is GPU-crackable from
 *   the stolen hashes, which would let one offline pass recover every user's
 *   codes.
 * - `verify()` uses `hash_equals()` for constant-time comparison.
 * - Codes are single-use secrets — the application must consume a code on
 *   success and rate-limit redemption attempts.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class RecoveryCodes
{
    /**
     * Generates a batch of formatted recovery codes.
     *
     * Each code is `$bytes` random bytes rendered as hex and grouped into
     * five-character blocks joined by hyphens for readability. The default of
     * 10 bytes gives 80 bits of entropy per code, which keeps the stored
     * unsalted SHA-256 hashes infeasible to brute-force offline after a breach.
     * Do not lower `$bytes` below 10 unless codes are hashed with a slow,
     * salted KDF instead; raise it for an even wider margin.
     *
     * ```php
     * $codes = RecoveryCodes::generate();       // 10 codes like "3f9ac-1b0e7-8d2f4-0a6c1"
     * $codes = RecoveryCodes::generate(8, 16);  // 8 codes, 128-bit each
     * ```
     *
     * @param int $count Number of codes to generate. Must be at least 1.
     * @param int $bytes Random bytes per code. Must be at least 1 (≥10 recommended for 80-bit entropy).
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException When `$count` or `$bytes` is less than 1.
     */
    public static function generate(int $count = 10, int $bytes = 10): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Recovery code count must be at least 1.');
        }

        if ($bytes < 1) {
            throw new \InvalidArgumentException('Recovery code byte length must be at least 1.');
        }

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes($bytes)), 5));
        }

        return $codes;
    }

    /**
     * Returns the SHA-256 hash of a recovery code as a lowercase hex string.
     *
     * The code is normalized first, so the stored hash is independent of
     * formatting and case. Store this value, never the raw code.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /**
     * Verifies a submitted recovery code against a stored SHA-256 hash.
     *
     * Normalizes the input, then compares with `hash_equals()` in constant time.
     * Always returns `false` when the normalized code or the stored hash is
     * empty (so all-separator input like `"-----"` cannot match). A `true`
     * result means the caller should now **consume** the matching stored hash
     * to enforce single use.
     */
    public static function verify(string $code, string $storedHash): bool
    {
        $normalized = self::normalize($code);

        if ($normalized === '' || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, hash('sha256', $normalized));
    }

    /**
     * Normalizes a recovery code by removing non-alphanumeric characters and
     * lowercasing, so hashing and comparison ignore separators and case.
     */
    public static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
