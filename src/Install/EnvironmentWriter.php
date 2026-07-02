<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * Writes a product's `.env` atomically during installation.
 *
 * The caller supplies an ordered map of `KEY => value`, so the toolkit carries no
 * product-specific keys — app name, tenancy defaults and the like belong to each
 * product's installer template. Values are serialised to be read back verbatim by
 * vlucas/phpdotenv (the loader NENE2 uses): a value is written bare when it is safe,
 * otherwise double-quoted with `\\`, `"` and `$` escaped, so passwords containing
 * quotes, spaces, `#` or `$` survive a round-trip. Line breaks and null bytes are
 * refused outright to prevent injecting extra `.env` lines.
 *
 * The write is atomic — a sibling temp file is written, `chmod`ed to 0640, then
 * renamed over the target — so an interrupted install never leaves a half-written
 * `.env`. If the file cannot be made non-world-readable it fails closed rather than
 * persist the secret in the clear. Part of the opt-in installer toolkit.
 */
final readonly class EnvironmentWriter
{
    /**
     * Serialise and atomically write the given values to `$path`.
     *
     * @param array<string, string> $values Ordered map of environment key => value.
     *
     * @throws RuntimeException on an invalid key, an unserialisable value, or a write failure.
     */
    public function write(string $path, array $values): void
    {
        $content = $this->serialize($values);

        $dir = dirname($path);

        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('The .env directory does not exist: %s', $dir));
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Could not write the .env file; check the directory permissions.');
        }

        @chmod($tmp, 0640);

        // .env carries the DB password and JWT secret. If chmod could not restrict it
        // (e.g. an unsupported filesystem left it world-readable at the umask default),
        // fail closed rather than persist a secret the whole host can read.
        $perms = fileperms($tmp);

        if ($perms !== false && ($perms & 0007) !== 0) {
            @unlink($tmp);

            throw new RuntimeException(
                'The .env file could not be restricted to non-world-readable permissions; '
                . 'refusing to write the database password where any host user could read it.',
            );
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Could not save the .env file; check the directory permissions.');
        }
    }

    /**
     * Serialise the values to `.env` text (one `KEY=value` line each, trailing newline).
     *
     * @param array<string, string> $values
     *
     * @throws RuntimeException on an invalid key or unserialisable value.
     */
    public function serialize(array $values): string
    {
        $lines = [];

        foreach ($values as $key => $value) {
            $lines[] = $this->line($key, $value);
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Generate a cryptographically secure secret as lowercase hex (e.g. a JWT signing key).
     * Kept here as a convenience so installers never invent their own weak secret; the value
     * is returned, never logged, and the caller places it in the value map.
     *
     * @throws RuntimeException if fewer than one byte is requested.
     */
    public static function generateSecret(int $bytes = 32): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('A secret needs at least one byte.');
        }

        return bin2hex(random_bytes($bytes));
    }

    private function line(string $key, string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            throw new RuntimeException(sprintf('Invalid environment key: %s', $key));
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new RuntimeException(
                sprintf('The value for %s contains a line break or null byte and cannot be written to .env.', $key),
            );
        }

        // Bare when the value is drawn entirely from a set phpdotenv reads back unchanged;
        // otherwise double-quote and escape the three characters that are special inside quotes.
        if ($value !== '' && preg_match('~^[A-Za-z0-9_./:@%+-]+$~', $value) === 1) {
            return $key . '=' . $value;
        }

        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return $key . '="' . $escaped . '"';
    }
}
