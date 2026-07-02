<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * Optional signature check performed BEFORE a release ZIP is extracted, on top of
 * the mandatory SHA-256 hash match in {@see PayloadInstaller}.
 *
 * Products that consume a signed release channel (e.g. NeNe Origin's detached-JWS
 * artifacts) inject an implementation; when none is provided the installer verifies
 * the hash only — the interim model where releases come from a GitHub release page
 * whose checksum the operator supplies. Implementations MUST throw on any failure
 * and return normally on success.
 */
interface PayloadSignatureVerifier
{
    /**
     * @param string $zipPath   Absolute path to the downloaded release ZIP on disk.
     * @param string $sha256Hex The already-verified lowercase-hex SHA-256 of the file.
     *
     * @throws RuntimeException if the signature is missing, malformed, or does not verify.
     */
    public function verify(string $zipPath, string $sha256Hex): void;
}
