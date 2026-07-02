<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * A validated release version manifest — the payload of NeNe Origin's profiled-TUF
 * "targets" object, parsed by {@see ReleaseManifestParser}.
 *
 * The property names are camelCase, but they map one-to-one onto the manifest's
 * snake_case keys (`artifact_url`, `artifact_sha256`, `min_php`, `min_supported_version`,
 * `requires`); the toolkit never invents its own key names, so the same descriptor is
 * produced whether the JSON came from GitHub today or Origin later. `minPhp` and
 * `minSupportedVersion` are exposed so an installer can gate with
 * {@see ServerRequirementChecker} BEFORE downloading a large artifact.
 */
final readonly class ReleaseDescriptor
{
    /**
     * @param string               $releasedAt         `latest.released_at` (RFC 3339 date-time).
     * @param string               $artifactUrl        `latest.artifact_url`.
     * @param string               $artifactSha256     `latest.artifact_sha256` (64 lowercase hex).
     * @param string               $minSupportedVersion Security floor; below it a client must upgrade.
     * @param list<string>         $channels           Advertised channels (`stable` / `beta` / `dev`).
     * @param string|null          $changelogUrl       `latest.changelog_url`, if present.
     * @param string|null          $minPhp             `latest.min_php` (e.g. `"8.2"`), if present.
     * @param array<string, string> $requires          `latest.requires`: sibling product slug => version range.
     */
    public function __construct(
        public int $specVersion,
        public int $schemaVersion,
        public string $product,
        public string $channel,
        public string $version,
        public string $releasedAt,
        public string $artifactUrl,
        public string $artifactSha256,
        public string $minSupportedVersion,
        public array $channels,
        public ?string $changelogUrl,
        public ?string $minPhp,
        public array $requires,
    ) {
    }
}
