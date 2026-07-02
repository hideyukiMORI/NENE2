<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * A pluggable source of product releases: it resolves the current version manifest and
 * downloads the release artifact to a temporary file.
 *
 * Its responsibility ends at download. Integrity (SHA-256), signatures, zip-slip and
 * extraction remain with {@see PayloadInstaller} so the verify-before-extract order is
 * never bypassed. Fetching the descriptor first lets an installer gate on
 * {@see ReleaseDescriptor::$minPhp} / `$minSupportedVersion` with a
 * {@see ServerRequirementChecker} BEFORE downloading a large artifact.
 *
 * A GitHub-backed implementation serves today; an Origin-backed one arrives later. Both
 * return the same {@see ReleaseDescriptor}, so switching sources is configuration only.
 */
interface ReleaseSource
{
    /**
     * Resolve and validate the current release manifest.
     *
     * @throws RuntimeException if the manifest cannot be fetched or fails validation.
     */
    public function fetchDescriptor(): ReleaseDescriptor;

    /**
     * Download the release artifact to $destinationPath (a caller-owned temp path).
     * Performs no verification or extraction.
     *
     * @param int $maxBytes Hard ceiling on the download size.
     *
     * @throws RuntimeException on an insecure URL, transport failure, or size overrun.
     */
    public function downloadArtifact(ReleaseDescriptor $descriptor, string $destinationPath, int $maxBytes): void;
}
