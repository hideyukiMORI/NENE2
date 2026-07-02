<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\HttpReleaseSource;
use Nene2\Install\HttpTransport;
use Nene2\Install\ReleaseDescriptor;
use Nene2\Install\ReleaseManifestParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpReleaseSourceTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->cleanup = [];
    }

    public function testFetchDescriptorBuildsTheManifestUrlAndReturnsTheDescriptor(): void
    {
        $transport = new class () implements HttpTransport {
            public string $lastGetUrl = '';
            public string $body = '';

            public function getString(string $url, int $timeoutSeconds): string
            {
                $this->lastGetUrl = $url;

                return $this->body;
            }

            public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void
            {
            }
        };
        $transport->body = $this->manifestJson('nene-invoice', 'stable');

        $source = new HttpReleaseSource(
            $transport,
            new ReleaseManifestParser(),
            'https://cdn.example.com/',
            'nene-invoice',
            'stable',
        );

        $descriptor = $source->fetchDescriptor();

        self::assertSame('https://cdn.example.com/nene-invoice/stable/latest.json', $transport->lastGetUrl);
        self::assertSame('1.4.0', $descriptor->version);
        self::assertSame('nene-invoice', $descriptor->product);
    }

    public function testFetchDescriptorRejectsANonHttpsBaseUrl(): void
    {
        $source = new HttpReleaseSource(
            $this->silentTransport(),
            new ReleaseManifestParser(),
            'http://cdn.example.com',
            'nene-invoice',
            'stable',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest URL must be https');
        $source->fetchDescriptor();
    }

    public function testFetchDescriptorRejectsAnInvalidManifest(): void
    {
        $transport = $this->transportReturning('{}');
        $source = new HttpReleaseSource($transport, new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest is invalid');
        $source->fetchDescriptor();
    }

    public function testFetchDescriptorRejectsAProductMismatch(): void
    {
        $transport = $this->transportReturning($this->manifestJson('nene-clear', 'stable'));
        $source = new HttpReleaseSource($transport, new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different product');
        $source->fetchDescriptor();
    }

    public function testFetchDescriptorRejectsAChannelMismatch(): void
    {
        $transport = $this->transportReturning($this->manifestJson('nene-invoice', 'beta'));
        $source = new HttpReleaseSource($transport, new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different channel');
        $source->fetchDescriptor();
    }

    public function testDownloadArtifactRejectsANonHttpsArtifactUrl(): void
    {
        $source = new HttpReleaseSource($this->silentTransport(), new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact URL must be https');
        $source->downloadArtifact($this->descriptor('http://cdn.example.com/a.zip'), $this->tempPath(), 1000);
    }

    public function testDownloadArtifactRejectsAnOverlongUrl(): void
    {
        $source = new HttpReleaseSource($this->silentTransport(), new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreasonably long');
        $source->downloadArtifact($this->descriptor('https://cdn.example.com/' . str_repeat('a', 2100)), $this->tempPath(), 1000);
    }

    public function testDownloadArtifactPassesThroughToTheTransport(): void
    {
        $transport = new class () implements HttpTransport {
            public string $lastDownloadUrl = '';
            public string $lastDestination = '';
            public int $lastMaxBytes = 0;

            public function getString(string $url, int $timeoutSeconds): string
            {
                return '';
            }

            public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void
            {
                $this->lastDownloadUrl = $url;
                $this->lastDestination = $destinationPath;
                $this->lastMaxBytes = $maxBytes;
                file_put_contents($destinationPath, 'ZIP');
            }
        };

        $source = new HttpReleaseSource($transport, new ReleaseManifestParser(), 'https://cdn.example.com', 'nene-invoice', 'stable');
        $dest = $this->tempPath();
        $url = 'https://cdn.example.com/nene-invoice/nene-invoice-1.4.0.zip';

        $source->downloadArtifact($this->descriptor($url), $dest, 50_000_000);

        self::assertSame($url, $transport->lastDownloadUrl);
        self::assertSame($dest, $transport->lastDestination);
        self::assertSame(50_000_000, $transport->lastMaxBytes);
        self::assertFileExists($dest);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function silentTransport(): HttpTransport
    {
        return new class () implements HttpTransport {
            public function getString(string $url, int $timeoutSeconds): string
            {
                return '';
            }

            public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void
            {
            }
        };
    }

    private function transportReturning(string $body): HttpTransport
    {
        return new class ($body) implements HttpTransport {
            public function __construct(private string $body)
            {
            }

            public function getString(string $url, int $timeoutSeconds): string
            {
                return $this->body;
            }

            public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void
            {
            }
        };
    }

    private function descriptor(string $artifactUrl): ReleaseDescriptor
    {
        return new ReleaseDescriptor(
            specVersion: 1,
            schemaVersion: 1,
            product: 'nene-invoice',
            channel: 'stable',
            version: '1.4.0',
            releasedAt: '2026-06-19T00:00:00Z',
            artifactUrl: $artifactUrl,
            artifactSha256: str_repeat('a', 64),
            minSupportedVersion: '1.2.0',
            channels: ['stable'],
            changelogUrl: null,
            minPhp: null,
            requires: [],
        );
    }

    private function manifestJson(string $product, string $channel): string
    {
        return json_encode([
            'spec_version' => 1,
            'schema_version' => 1,
            'product' => $product,
            'channel' => $channel,
            'latest' => [
                'version' => '1.4.0',
                'released_at' => '2026-06-19T00:00:00Z',
                'artifact_url' => 'https://cdn.example.com/' . $product . '/' . $product . '-1.4.0.zip',
                'artifact_sha256' => str_repeat('a', 64),
            ],
            'min_supported_version' => '1.2.0',
            'channels' => [$channel],
        ], JSON_THROW_ON_ERROR);
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/nene2-relsrc-' . bin2hex(random_bytes(6)) . '.zip';
        $this->cleanup[] = $path;

        return $path;
    }
}
