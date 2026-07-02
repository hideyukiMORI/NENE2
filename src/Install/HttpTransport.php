<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * The minimal HTTP surface a {@see ReleaseSource} needs: fetch a small text resource
 * (the manifest) and download a binary artifact to disk with a size ceiling.
 *
 * Extracting it behind an interface keeps {@see HttpReleaseSource} unit-testable with a
 * fake, and lets a product swap in its own client. The default {@see CurlHttpTransport}
 * restricts every request — including redirects — to https. Part of the opt-in installer
 * toolkit.
 */
interface HttpTransport
{
    /**
     * GET a small text resource and return its body.
     *
     * @throws RuntimeException on a transport error, a non-2xx status, or a body larger than the
     *         implementation's in-memory ceiling.
     */
    public function getString(string $url, int $timeoutSeconds): string;

    /**
     * Download a resource to $destinationPath, aborting if it would exceed $maxBytes.
     * Implementations must not extract or otherwise interpret the bytes.
     *
     * @throws RuntimeException on a transport error, a non-2xx status, or when the body exceeds $maxBytes.
     */
    public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void;
}
