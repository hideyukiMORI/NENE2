<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The outcome of parsing a release manifest: either a validated {@see ReleaseDescriptor}
 * or a list of machine-readable reason codes. Modelled as a result (not an exception) so
 * callers and tests can inspect exactly why a manifest was rejected.
 */
final readonly class ReleaseManifestResult
{
    /**
     * @param list<string> $errors Reason codes, e.g. `['schema_version_unsupported']`,
     *                             `['artifact_sha256_invalid', 'channel_invalid']`. Empty when valid.
     */
    public function __construct(
        public bool $valid,
        public ?ReleaseDescriptor $descriptor,
        public array $errors,
    ) {
    }

    public static function ok(ReleaseDescriptor $descriptor): self
    {
        return new self(true, $descriptor, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, null, $errors);
    }
}
