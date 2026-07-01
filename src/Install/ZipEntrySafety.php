<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Pure, I/O-free safety checks for entries inside a release ZIP — the zip-slip
 * defence {@see PayloadInstaller} applies to every entry before extracting.
 *
 * Kept free of filesystem and {@see \ZipArchive} access so the rules are trivially
 * unit-testable and reusable by any product's installer. Part of the opt-in
 * installer toolkit: nothing here runs unless a product's installer calls it.
 */
final class ZipEntrySafety
{
    /**
     * Whether a ZIP entry name would write OUTSIDE the extraction root (zip-slip).
     *
     * Absolute paths (`/foo`), Windows drive letters (`C:\`) and any `..` segment
     * are all treated as an escape. Backslashes are normalised to `/` first so a
     * Windows-style separator cannot smuggle a traversal past the check.
     */
    public static function escapesRoot(string $entry): bool
    {
        $normalized = str_replace('\\', '/', $entry);

        if ($normalized === '' || $normalized === '/') {
            return false;
        }

        // Absolute path or Windows drive letter → always an escape.
        if ($normalized[0] === '/' || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            return true;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * The first path segment of a ZIP entry (its top-level directory or file),
     * used to confine an archive to a product's allow-list of expected entries.
     */
    public static function topSegment(string $entry): string
    {
        $normalized = ltrim(str_replace('\\', '/', $entry), '/');
        $slash = strpos($normalized, '/');

        return $slash === false ? $normalized : substr($normalized, 0, $slash);
    }
}
