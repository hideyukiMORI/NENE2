<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\ReleaseManifestParser;
use Nene2\Install\ReleaseManifestResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseManifestParserTest extends TestCase
{
    public function testParsesAValidManifest(): void
    {
        $result = $this->parse($this->validManifest());

        self::assertTrue($result->valid, implode(',', $result->errors));
        self::assertSame([], $result->errors);
        self::assertNotNull($result->descriptor);

        $descriptor = $result->descriptor;
        self::assertSame(1, $descriptor->specVersion);
        self::assertSame(1, $descriptor->schemaVersion);
        self::assertSame('nene-invoice', $descriptor->product);
        self::assertSame('stable', $descriptor->channel);
        self::assertSame('1.4.0', $descriptor->version);
        self::assertSame('2026-06-19T00:00:00Z', $descriptor->releasedAt);
        self::assertSame('https://cdn.nene-origin.dev/nene-invoice/nene-invoice-1.4.0.zip', $descriptor->artifactUrl);
        self::assertSame('3b1f2c9d8e7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c', $descriptor->artifactSha256);
        self::assertSame('1.2.0', $descriptor->minSupportedVersion);
        self::assertSame(['stable', 'beta'], $descriptor->channels);
        self::assertSame('https://cdn.nene-origin.dev/nene-invoice/changelog/1.4.0', $descriptor->changelogUrl);
        self::assertSame('8.2', $descriptor->minPhp);
        self::assertSame(['nene-invoice' => '>=1.3.0'], $descriptor->requires);
    }

    public function testToleratesUnknownAdditionalKeysWithinAKnownMajor(): void
    {
        $manifest = $this->validManifest();
        $manifest['future_top_level'] = 'ignored';
        $latest = $manifest['latest'];
        self::assertIsArray($latest);
        $latest['future_nested'] = 'ignored';
        $manifest['latest'] = $latest;

        $result = $this->parse($manifest);

        self::assertTrue($result->valid, implode(',', $result->errors));
    }

    public function testOptionalLatestFieldsMayBeAbsent(): void
    {
        $manifest = $this->validManifest();
        $latest = $manifest['latest'];
        self::assertIsArray($latest);
        unset($latest['changelog_url'], $latest['min_php'], $latest['requires']);
        $manifest['latest'] = $latest;

        $result = $this->parse($manifest);

        self::assertTrue($result->valid, implode(',', $result->errors));
        self::assertNotNull($result->descriptor);
        self::assertNull($result->descriptor->changelogUrl);
        self::assertNull($result->descriptor->minPhp);
        self::assertSame([], $result->descriptor->requires);
    }

    #[DataProvider('nonObjectJson')]
    public function testRejectsNonObjectJson(string $json, string $expected): void
    {
        $result = (new ReleaseManifestParser())->parse($json);

        self::assertFalse($result->valid);
        self::assertSame([$expected], $result->errors);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonObjectJson(): iterable
    {
        yield 'broken json' => ['{not valid', 'invalid_json'];
        yield 'json array' => ['[1, 2, 3]', 'not_an_object'];
        yield 'empty object' => ['{}', 'not_an_object'];
        yield 'scalar' => ['42', 'not_an_object'];
    }

    #[DataProvider('unknownSchemaMajors')]
    public function testRejectsUnknownSchemaMajor(mixed $schemaVersion, string $expected): void
    {
        $manifest = $this->validManifest();
        if ($schemaVersion === '__unset__') {
            unset($manifest['schema_version']);
        } else {
            $manifest['schema_version'] = $schemaVersion;
        }

        $result = $this->parse($manifest);

        self::assertFalse($result->valid);
        self::assertSame([$expected], $result->errors);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unknownSchemaMajors(): iterable
    {
        yield 'future major' => [2, 'schema_version_unsupported'];
        yield 'zero' => [0, 'schema_version_unsupported'];
        yield 'missing' => ['__unset__', 'schema_version_missing'];
        yield 'not an int' => ['1', 'schema_version_missing'];
    }

    #[DataProvider('missingTopFields')]
    public function testReportsMissingRequiredTopLevelFields(string $key, string $expected): void
    {
        $manifest = $this->validManifest();
        unset($manifest[$key]);

        $result = $this->parse($manifest);

        self::assertFalse($result->valid);
        self::assertContains($expected, $result->errors);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingTopFields(): iterable
    {
        yield 'spec_version' => ['spec_version', 'spec_version_missing'];
        yield 'product' => ['product', 'product_missing'];
        yield 'channel' => ['channel', 'channel_missing'];
        yield 'min_supported_version' => ['min_supported_version', 'min_supported_version_missing'];
        yield 'channels' => ['channels', 'channels_missing'];
        yield 'latest' => ['latest', 'latest_missing'];
    }

    #[DataProvider('missingLatestFields')]
    public function testReportsMissingRequiredLatestFields(string $key, string $expected): void
    {
        $manifest = $this->validManifest();
        $latest = $manifest['latest'];
        self::assertIsArray($latest);
        unset($latest[$key]);
        $manifest['latest'] = $latest;

        $result = $this->parse($manifest);

        self::assertFalse($result->valid);
        self::assertContains($expected, $result->errors);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingLatestFields(): iterable
    {
        yield 'version' => ['version', 'latest_version_missing'];
        yield 'released_at' => ['released_at', 'released_at_missing'];
        yield 'artifact_url' => ['artifact_url', 'artifact_url_missing'];
        yield 'artifact_sha256' => ['artifact_sha256', 'artifact_sha256_missing'];
    }

    #[DataProvider('malformedTopValues')]
    public function testRejectsMalformedTopLevelValues(string $key, mixed $value, string $expected): void
    {
        $manifest = $this->validManifest();
        $manifest[$key] = $value;

        $result = $this->parse($manifest);

        self::assertFalse($result->valid);
        self::assertContains($expected, $result->errors);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedTopValues(): iterable
    {
        yield 'product with spaces' => ['product', 'Bad Product', 'product_invalid'];
        yield 'product uppercase' => ['product', 'NeneInvoice', 'product_invalid'];
        yield 'unknown channel' => ['channel', 'nightly', 'channel_invalid'];
        yield 'non-semver floor' => ['min_supported_version', '1.2', 'min_supported_version_invalid'];
        yield 'channels not a list' => ['channels', ['stable' => true], 'channels_invalid'];
        yield 'channels with bad entry' => ['channels', ['stable', 'nightly'], 'channels_invalid'];
        yield 'empty channels' => ['channels', [], 'channels_invalid'];
    }

    #[DataProvider('malformedLatestValues')]
    public function testRejectsMalformedLatestValues(string $key, mixed $value, string $expected): void
    {
        $manifest = $this->validManifest();
        $latest = $manifest['latest'];
        self::assertIsArray($latest);
        $latest[$key] = $value;
        $manifest['latest'] = $latest;

        $result = $this->parse($manifest);

        self::assertFalse($result->valid);
        self::assertContains($expected, $result->errors);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedLatestValues(): iterable
    {
        yield 'non-semver version' => ['version', '1.4', 'latest_version_invalid'];
        yield 'uppercase sha256' => ['artifact_sha256', str_repeat('A', 64), 'artifact_sha256_invalid'];
        yield 'short sha256' => ['artifact_sha256', 'abc123', 'artifact_sha256_invalid'];
        yield 'bad min_php' => ['min_php', '8', 'min_php_invalid'];
        yield 'requires as list' => ['requires', ['a', 'b'], 'requires_invalid'];
        yield 'requires with non-string value' => ['requires', ['nene-x' => 123], 'requires_invalid'];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $manifest
     */
    private function parse(array $manifest): ReleaseManifestResult
    {
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);

        return (new ReleaseManifestParser())->parse($json);
    }

    /**
     * A valid manifest mirroring origin/docs/spec/examples/targets.example.json.
     *
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'spec_version' => 1,
            'schema_version' => 1,
            'product' => 'nene-invoice',
            'channel' => 'stable',
            'latest' => [
                'version' => '1.4.0',
                'released_at' => '2026-06-19T00:00:00Z',
                'artifact_url' => 'https://cdn.nene-origin.dev/nene-invoice/nene-invoice-1.4.0.zip',
                'artifact_sha256' => '3b1f2c9d8e7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c',
                'changelog_url' => 'https://cdn.nene-origin.dev/nene-invoice/changelog/1.4.0',
                'min_php' => '8.2',
                'requires' => ['nene-invoice' => '>=1.3.0'],
            ],
            'min_supported_version' => '1.2.0',
            'channels' => ['stable', 'beta'],
            'provenance' => [
                'source_commit' => '3b1f2c9d8e7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c',
                'builder_role_id' => 'origin-release-builder',
                'built_at' => '2026-06-19T00:00:00Z',
                'reproducible' => true,
            ],
        ];
    }
}
