<?php

declare(strict_types=1);

namespace Nene2\Install;

use JsonException;

/**
 * Parses and validates a release version manifest (NeNe Origin's profiled-TUF "targets"
 * object) into a {@see ReleaseDescriptor}.
 *
 * The field names and constraints mirror `origin/docs/spec/targets.schema.json` exactly —
 * snake_case keys, the `stable|beta|dev` channel enum, the semver and 64-hex-sha256
 * patterns — so a manifest served from GitHub today and from Origin later parse
 * identically. Unknown schema majors are rejected outright (the spec mandates "clients
 * reject unknown majors"). Unknown *additional* keys within a known major are tolerated,
 * so a future additive change does not break older installers.
 *
 * Pure and I/O-free: it neither fetches nor trusts the network. Transport, TLS policy and
 * download live in a {@see ReleaseSource}; signature/hash verification and extraction stay
 * in {@see PayloadInstaller}. Part of the opt-in installer toolkit.
 */
final readonly class ReleaseManifestParser
{
    /** The manifest schema major this client understands; any other major is rejected. */
    public const SUPPORTED_SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const CHANNELS = ['stable', 'beta', 'dev'];

    private const SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';
    private const SHA256_PATTERN = '/^[a-f0-9]{64}$/';
    private const PRODUCT_PATTERN = '/^[a-z][a-z0-9-]*$/';
    private const MIN_PHP_PATTERN = '/^[0-9]+\.[0-9]+$/';

    public function parse(string $json): ReleaseManifestResult
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ReleaseManifestResult::invalid(['invalid_json']);
        }

        // A JSON object decodes to a non-list array; a list or empty array is not a manifest.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return ReleaseManifestResult::invalid(['not_an_object']);
        }

        return $this->validate($decoded);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function validate(array $data): ReleaseManifestResult
    {
        // Reject unknown schema majors before trusting any other field.
        $schemaVersion = $data['schema_version'] ?? null;

        if (!is_int($schemaVersion)) {
            return ReleaseManifestResult::invalid(['schema_version_missing']);
        }

        if ($schemaVersion !== self::SUPPORTED_SCHEMA_VERSION) {
            return ReleaseManifestResult::invalid(['schema_version_unsupported']);
        }

        /** @var list<string> $errors */
        $errors = [];

        $specVersion = $data['spec_version'] ?? null;

        if ($specVersion === null) {
            $errors[] = 'spec_version_missing';
            $specVersion = 0;
        } elseif (!is_int($specVersion) || $specVersion < 1) {
            $errors[] = 'spec_version_invalid';
            $specVersion = 0;
        }

        $product = $this->stringField($data, 'product', 'product', $errors);

        if ($product !== null && preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            $errors[] = 'product_invalid';
        }

        $channel = $this->stringField($data, 'channel', 'channel', $errors);

        if ($channel !== null && !in_array($channel, self::CHANNELS, true)) {
            $errors[] = 'channel_invalid';
        }

        $minSupported = $this->stringField($data, 'min_supported_version', 'min_supported_version', $errors);

        if ($minSupported !== null && preg_match(self::SEMVER_PATTERN, $minSupported) !== 1) {
            $errors[] = 'min_supported_version_invalid';
        }

        $channels = $this->channelsField($data, $errors);

        $latest = $data['latest'] ?? null;
        $version = null;
        $releasedAt = null;
        $artifactUrl = null;
        $artifactSha256 = null;
        $changelogUrl = null;
        $minPhp = null;
        $requires = [];

        if (!is_array($latest) || array_is_list($latest)) {
            $errors[] = 'latest_missing';
        } else {
            $version = $this->stringField($latest, 'version', 'latest_version', $errors);

            if ($version !== null && preg_match(self::SEMVER_PATTERN, $version) !== 1) {
                $errors[] = 'latest_version_invalid';
            }

            $releasedAt = $this->stringField($latest, 'released_at', 'released_at', $errors);
            $artifactUrl = $this->stringField($latest, 'artifact_url', 'artifact_url', $errors);
            $artifactSha256 = $this->stringField($latest, 'artifact_sha256', 'artifact_sha256', $errors);

            if ($artifactSha256 !== null && preg_match(self::SHA256_PATTERN, $artifactSha256) !== 1) {
                $errors[] = 'artifact_sha256_invalid';
            }

            $changelogUrl = $this->optionalStringField($latest, 'changelog_url', 'changelog_url_invalid', $errors);

            $minPhp = $this->optionalStringField($latest, 'min_php', 'min_php_invalid', $errors);

            if ($minPhp !== null && preg_match(self::MIN_PHP_PATTERN, $minPhp) !== 1) {
                $errors[] = 'min_php_invalid';
                $minPhp = null;
            }

            $requires = $this->requiresField($latest['requires'] ?? null, $errors);
        }

        if (
            $errors !== []
            || $product === null
            || $channel === null
            || $minSupported === null
            || $version === null
            || $releasedAt === null
            || $artifactUrl === null
            || $artifactSha256 === null
        ) {
            return ReleaseManifestResult::invalid($errors === [] ? ['not_an_object'] : $errors);
        }

        return ReleaseManifestResult::ok(new ReleaseDescriptor(
            specVersion: $specVersion,
            schemaVersion: $schemaVersion,
            product: $product,
            channel: $channel,
            version: $version,
            releasedAt: $releasedAt,
            artifactUrl: $artifactUrl,
            artifactSha256: $artifactSha256,
            minSupportedVersion: $minSupported,
            channels: $channels,
            changelogUrl: $changelogUrl,
            minPhp: $minPhp,
            requires: $requires,
        ));
    }

    /**
     * A required string field: records `<code>_missing` / `<code>_invalid` and returns null on failure.
     *
     * @param array<array-key, mixed> $data
     * @param list<string>            $errors
     */
    private function stringField(array $data, string $key, string $code, array &$errors): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            $errors[] = $code . '_missing';

            return null;
        }

        if (!is_string($value) || $value === '') {
            $errors[] = $code . '_invalid';

            return null;
        }

        return $value;
    }

    /**
     * An optional string field: null when absent, records `$invalidCode` when present but not a string.
     *
     * @param array<array-key, mixed> $data
     * @param list<string>            $errors
     */
    private function optionalStringField(array $data, string $key, string $invalidCode, array &$errors): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            $errors[] = $invalidCode;

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $errors
     *
     * @return list<string>
     */
    private function channelsField(array $data, array &$errors): array
    {
        $value = $data['channels'] ?? null;

        if ($value === null) {
            $errors[] = 'channels_missing';

            return [];
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = 'channels_invalid';

            return [];
        }

        $channels = [];

        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, self::CHANNELS, true)) {
                $errors[] = 'channels_invalid';

                return [];
            }

            $channels[] = $item;
        }

        return $channels;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string>
     */
    private function requiresField(mixed $value, array &$errors): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'requires_invalid';

            return [];
        }

        $requires = [];

        foreach ($value as $key => $range) {
            if (!is_string($key) || !is_string($range)) {
                $errors[] = 'requires_invalid';

                return [];
            }

            $requires[$key] = $range;
        }

        return $requires;
    }
}
