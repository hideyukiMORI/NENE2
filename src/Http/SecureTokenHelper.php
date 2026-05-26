<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Utilities for generating, hashing, and verifying cryptographically secure tokens.
 *
 * The standard pattern used in password resets, magic links, API keys, invitation
 * tokens, and signed URLs always looks the same:
 *
 * 1. Generate a high-entropy raw token.
 * 2. Store only the SHA-256 hash — never the raw value.
 * 3. Verify incoming tokens with a timing-safe comparison.
 *
 * This class codifies that pattern so that each implementation does not have to
 * repeat the same low-level calls.
 *
 * ```php
 * // Generating a new token (e.g. password reset, magic link, invitation)
 * $raw  = SecureTokenHelper::generate();          // 64-char hex string, 256-bit entropy
 * $hash = SecureTokenHelper::hash($raw);          // SHA-256 hex — store this in the DB
 * // → send $raw to the user; persist $hash
 *
 * // Verifying an incoming token
 * $storedHash = $repo->findHashByEmail($email);   // retrieved from DB
 * if (!SecureTokenHelper::verify($incoming, $storedHash)) {
 *     // token invalid — return 400/404/422
 * }
 * ```
 *
 * Security notes:
 * - `generate()` uses `random_bytes()` — CSPRNG, suitable for security-sensitive tokens.
 * - `hash()` uses SHA-256. A DB breach exposes only the hash; reversing it on a
 *   256-bit random value is computationally infeasible.
 * - `verify()` uses `hash_equals()` — constant-time comparison prevents timing attacks.
 *   Never use `===` or `==` to compare token hashes.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class SecureTokenHelper
{
    /**
     * Generates a cryptographically secure random token.
     *
     * Returns a lowercase hexadecimal string of length `$bytes * 2`.
     * The default of 32 bytes gives 256 bits of entropy and a 64-character string.
     *
     * ```php
     * $raw = SecureTokenHelper::generate();     // e.g. "a3f2c8..."  (64 chars)
     * $raw = SecureTokenHelper::generate(16);   // 32-char token (128-bit)
     * ```
     *
     * @param int<1, max> $bytes Number of random bytes. Must be at least 1.
     */
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Returns the SHA-256 hash of a raw token as a lowercase hex string.
     *
     * Store this in the database, never the raw token.
     *
     * ```php
     * $hash = SecureTokenHelper::hash($rawToken); // 64-char hex string
     * ```
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Verifies that a raw token matches a stored SHA-256 hash.
     *
     * Uses `hash_equals()` internally — the comparison runs in constant time
     * regardless of where the strings differ, preventing timing-oracle attacks.
     *
     * Returns `true` only when the token is valid. Always returns `false` for
     * empty inputs so callers do not need to guard against blank header values.
     *
     * ```php
     * if (!SecureTokenHelper::verify($incoming, $storedHash)) {
     *     return $this->problems->create($request, 'not-found', 'Not Found.', 404, '');
     * }
     * ```
     */
    public static function verify(string $rawToken, string $storedHash): bool
    {
        if ($rawToken === '' || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($rawToken));
    }

    /**
     * Generates a token and its SHA-256 hash in one call.
     *
     * Returns `[$rawToken, $hash]`. Use `$rawToken` to send to the user and
     * `$hash` to persist in the database.
     *
     * ```php
     * [$raw, $hash] = SecureTokenHelper::generateWithHash();
     * $repo->storeResetToken($userId, $hash, $expiresAt);
     * $mailer->sendResetLink($email, $raw);
     * ```
     *
     * @param int<1, max> $bytes Number of random bytes. Must be at least 1.
     *
     * @return array{0: string, 1: string}
     */
    public static function generateWithHash(int $bytes = 32): array
    {
        $raw = self::generate($bytes);

        return [$raw, self::hash($raw)];
    }
}
