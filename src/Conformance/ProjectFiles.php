<?php

declare(strict_types=1);

namespace Nene2\Conformance;

/**
 * Filesystem helpers shared by the file-scanning rules.
 *
 * Centralises path normalisation so every finding carries a stable, root-relative
 * POSIX path regardless of the caller's working directory.
 */
final class ProjectFiles
{
    /**
     * Absolute paths of every `*.php` file under `$root/$subdir`, sorted.
     *
     * Returns an empty list when the directory is absent (a consumer with no
     * `src/` simply produces no findings for the source-scanning rules).
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $root, string $subdir): array
    {
        $base = $root . '/' . $subdir;

        if (!is_dir($base)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Root-relative POSIX path for an absolute file path.
     */
    public static function relativePath(string $root, string $absolute): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $absolute);

        if (str_starts_with($normalized, $normalizedRoot)) {
            return substr($normalized, strlen($normalizedRoot));
        }

        return $normalized;
    }
}
