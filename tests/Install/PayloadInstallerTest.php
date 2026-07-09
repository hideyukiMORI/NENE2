<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\PayloadInstaller;
use Nene2\Install\PayloadSignatureVerifier;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

// Skip (instead of Error) on environments without ext-zip; the app image ships it (#1527).
#[RequiresPhpExtension('zip')]
final class PayloadInstallerTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    /** @var list<string> */
    private const ALLOWED = ['src', 'vendor', 'composer.json', 'database', 'public_html'];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->removePath($path);
        }

        $this->cleanup = [];
    }

    public function testExtractsAValidPayload(): void
    {
        [$zip, $sha] = $this->makeZip([
            'composer.json' => '{}',
            'src/App.php' => '<?php',
            'vendor/autoload.php' => '<?php',
        ]);
        $dest = $this->makeDir();

        (new PayloadInstaller())->verifyAndExtract($zip, $sha, $dest, self::ALLOWED);

        self::assertFileExists($dest . '/composer.json');
        self::assertFileExists($dest . '/src/App.php');
        self::assertSame('{}', file_get_contents($dest . '/composer.json'));
    }

    public function testRejectsAChecksumMismatch(): void
    {
        [$zip] = $this->makeZip(['composer.json' => '{}']);
        $dest = $this->makeDir();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        (new PayloadInstaller())->verifyAndExtract($zip, str_repeat('0', 64), $dest, self::ALLOWED);
    }

    public function testRejectsAMalformedChecksum(): void
    {
        [$zip] = $this->makeZip(['composer.json' => '{}']);
        $dest = $this->makeDir();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('64 hexadecimal');
        (new PayloadInstaller())->verifyAndExtract($zip, 'not-a-real-hash', $dest, self::ALLOWED);
    }

    public function testRejectsAnEmptyChecksum(): void
    {
        [$zip] = $this->makeZip(['composer.json' => '{}']);
        $dest = $this->makeDir();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('required');
        (new PayloadInstaller())->verifyAndExtract($zip, '   ', $dest, self::ALLOWED);
    }

    public function testRejectsAnUnexpectedTopLevelEntry(): void
    {
        [$zip, $sha] = $this->makeZip([
            'composer.json' => '{}',
            'evil/shell.php' => '<?php',
        ]);
        $dest = $this->makeDir();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected top-level entry');
        (new PayloadInstaller())->verifyAndExtract($zip, $sha, $dest, self::ALLOWED);
    }

    public function testRejectsAZipSlipEntry(): void
    {
        [$zip, $sha] = $this->makeZip([
            'composer.json' => '{}',
            '../escape.php' => '<?php',
        ]);
        $dest = $this->makeDir();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zip-slip');
        (new PayloadInstaller())->verifyAndExtract($zip, $sha, $dest, self::ALLOWED);
    }

    public function testRunsAnInjectedSignatureVerifier(): void
    {
        [$zip, $sha] = $this->makeZip(['composer.json' => '{}']);
        $dest = $this->makeDir();

        $verifier = new class () implements PayloadSignatureVerifier {
            public int $calls = 0;
            public string $seenHash = '';

            public function verify(string $zipPath, string $sha256Hex): void
            {
                $this->calls++;
                $this->seenHash = $sha256Hex;
            }
        };

        (new PayloadInstaller($verifier))->verifyAndExtract($zip, $sha, $dest, self::ALLOWED);

        self::assertSame(1, $verifier->calls);
        self::assertSame($sha, $verifier->seenHash);
        self::assertFileExists($dest . '/composer.json');
    }

    public function testPropagatesASignatureFailureBeforeExtracting(): void
    {
        [$zip, $sha] = $this->makeZip(['composer.json' => '{}']);
        $dest = $this->makeDir();

        $verifier = new class () implements PayloadSignatureVerifier {
            public function verify(string $zipPath, string $sha256Hex): void
            {
                throw new RuntimeException('signature invalid');
            }
        };

        try {
            (new PayloadInstaller($verifier))->verifyAndExtract($zip, $sha, $dest, self::ALLOWED);
            self::fail('expected the signature failure to propagate');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('signature invalid', $exception->getMessage());
        }

        // The signature ran before extraction, so nothing was written to disk.
        self::assertFileDoesNotExist($dest . '/composer.json');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $entries
     *
     * @return array{0: string, 1: string} [zipPath, lowercase-hex sha256]
     */
    private function makeZip(array $entries): array
    {
        $path = $this->tempPath('zip');
        $zip = new ZipArchive();

        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $this->cleanup[] = $path;

        $sha = hash_file('sha256', $path);

        if ($sha === false) {
            self::fail('could not hash the test ZIP');
        }

        return [$path, $sha];
    }

    private function makeDir(): string
    {
        $dir = $this->tempPath('dir');
        self::assertTrue(mkdir($dir, 0777, true));
        $this->cleanup[] = $dir;

        return $dir;
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/nene2-install-' . bin2hex(random_bytes(6)) . '-' . $suffix;
    }

    private function removePath(string $path): void
    {
        if (is_dir($path)) {
            $items = scandir($path);

            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $this->removePath($path . '/' . $item);
                    }
                }
            }

            @rmdir($path);

            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
