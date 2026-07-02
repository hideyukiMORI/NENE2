<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * An {@see ReleaseSource} that reads the manifest and artifact over HTTP(S).
 *
 * The manifest lives at `{baseUrl}/{product}/{channel}/latest.json`. The base URL,
 * product slug and channel are all injected — the toolkit bakes in no default origin or
 * product (the lesson from dropping `TenantConfigurationValidator::standard()`), so a
 * product wires its own coordinates.
 *
 * Security: both the manifest URL and the artifact URL must be https (the transport also
 * confines redirects to https) and within a sane length; the fetched manifest must be for
 * the product and channel that were requested. Download goes straight to a caller-owned
 * temp path — verification and extraction stay in {@see PayloadInstaller}.
 */
final readonly class HttpReleaseSource implements ReleaseSource
{
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private HttpTransport $transport,
        private ReleaseManifestParser $parser,
        private string $baseUrl,
        private string $product,
        private string $channel,
        private int $timeoutSeconds = 30,
    ) {
    }

    public function fetchDescriptor(): ReleaseDescriptor
    {
        $url = $this->manifestUrl();
        $this->assertHttps($url, 'The release manifest URL must be https.');

        $result = $this->parser->parse($this->transport->getString($url, $this->timeoutSeconds));

        if (!$result->valid || $result->descriptor === null) {
            throw new RuntimeException('The release manifest is invalid: ' . implode(', ', $result->errors));
        }

        $descriptor = $result->descriptor;

        if ($descriptor->product !== $this->product) {
            throw new RuntimeException('The release manifest is for a different product than requested.');
        }

        if ($descriptor->channel !== $this->channel) {
            throw new RuntimeException('The release manifest is for a different channel than requested.');
        }

        return $descriptor;
    }

    public function downloadArtifact(ReleaseDescriptor $descriptor, string $destinationPath, int $maxBytes): void
    {
        $this->assertHttps($descriptor->artifactUrl, 'The release artifact URL must be https.');

        $this->transport->download($descriptor->artifactUrl, $destinationPath, $this->timeoutSeconds, $maxBytes);
    }

    private function manifestUrl(): string
    {
        // product and channel are constrained slugs, but encode defensively so a stray value
        // can never climb out of the path.
        return rtrim($this->baseUrl, '/')
            . '/' . rawurlencode($this->product)
            . '/' . rawurlencode($this->channel)
            . '/latest.json';
    }

    private function assertHttps(string $url, string $message): void
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new RuntimeException('The release URL is unreasonably long; refusing to fetch it.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new RuntimeException($message);
        }
    }
}
