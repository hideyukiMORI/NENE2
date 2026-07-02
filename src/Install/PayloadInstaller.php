<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;
use ZipArchive;

/**
 * Verifies and extracts a release ZIP on shared hosting — the security core of the
 * opt-in installer toolkit. The order is fixed and defensive:
 *
 *   1. the file's SHA-256 matches the expected hash (timing-safe compare);
 *   2. an optional signature is verified (e.g. NeNe Origin detached-JWS) when a
 *      {@see PayloadSignatureVerifier} is injected;
 *   3. every entry is rejected for zip-slip and confined to the product's allow-list
 *      of top-level entries;
 *   4. only then is the archive extracted.
 *
 * The verify-before-extract order guarantees a *rejected* release writes nothing. It does
 * NOT make extraction itself atomic: an error partway through (e.g. the disk fills) can
 * leave a partial tree. Callers must therefore extract into a fresh staging directory and
 * swap it into place atomically — never extract directly over a live installation.
 *
 * The allow-list is supplied by the caller, so the toolkit carries no product
 * assumptions. Framework-level and opt-in: nothing here runs unless a product's
 * installer calls it.
 */
final readonly class PayloadInstaller
{
    public function __construct(
        private ?PayloadSignatureVerifier $signatureVerifier = null,
    ) {
    }

    /**
     * @param string       $zipPath           Absolute path to the release ZIP.
     * @param string       $expectedSha256    Expected SHA-256 (64 hexadecimal characters).
     * @param string       $destination       Staging directory to extract into (must exist and be
     *                                         writable; see the class docblock on atomic swap).
     * @param list<string> $allowedTopEntries Top-level entries the product's release may contain,
     *                                         e.g. `['src', 'vendor', 'database', 'public_html',
     *                                         'composer.json', 'phinx.php', '.env.example', 'var']`.
     *
     * @throws RuntimeException on any verification or extraction failure. Verification always runs
     *         before extraction, so a rejected release never writes to $destination. Extraction
     *         itself is not atomic, though: a failure partway through can leave a partial tree, so
     *         $destination should be a throwaway staging directory (see the class docblock), not a
     *         live install root.
     */
    public function verifyAndExtract(
        string $zipPath,
        string $expectedSha256,
        string $destination,
        array $allowedTopEntries,
    ): void {
        $sha256 = $this->assertSha256($zipPath, $expectedSha256);
        $this->signatureVerifier?->verify($zipPath, $sha256);
        $this->extract($zipPath, $destination, $allowedTopEntries);
    }

    /**
     * @return string The verified lowercase-hex SHA-256, reused by the signature verifier.
     */
    private function assertSha256(string $zipPath, string $expectedSha256): string
    {
        $expected = strtolower(trim($expectedSha256));

        if ($expected === '') {
            throw new RuntimeException('A SHA-256 checksum is required to verify the release.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new RuntimeException('The SHA-256 checksum must be 64 hexadecimal characters.');
        }

        $actual = hash_file('sha256', $zipPath);

        if ($actual === false) {
            throw new RuntimeException('The release file could not be read to compute its checksum.');
        }

        // Timing-safe compare; verification is always done before any extraction.
        if (!hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('The release SHA-256 does not match; refusing to install.');
        }

        return $expected;
    }

    /**
     * @param list<string> $allowedTopEntries
     */
    private function extract(string $zipPath, string $destination, array $allowedTopEntries): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension (ZipArchive) is not available.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The release ZIP could not be opened.');
        }

        try {
            if ($zip->numFiles === 0) {
                throw new RuntimeException('The release ZIP is empty.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);

                if ($entry === false) {
                    throw new RuntimeException('A ZIP entry name could not be read.');
                }

                if (ZipEntrySafety::escapesRoot($entry)) {
                    throw new RuntimeException('The release ZIP contains an unsafe path (possible zip-slip).');
                }

                $top = ZipEntrySafety::topSegment($entry);

                if ($top === '') {
                    throw new RuntimeException('The release ZIP contains an entry with an empty path.');
                }

                if (!in_array($top, $allowedTopEntries, true)) {
                    throw new RuntimeException(
                        sprintf('The release ZIP contains an unexpected top-level entry: %s', $top),
                    );
                }
            }

            if ($zip->extractTo($destination) !== true) {
                throw new RuntimeException(
                    'The release ZIP could not be extracted (check permissions and disk space).',
                );
            }
        } finally {
            $zip->close();
        }
    }
}
