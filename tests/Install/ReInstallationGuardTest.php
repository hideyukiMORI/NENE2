<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\ProvisioningProbe;
use Nene2\Install\ReInstallationGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReInstallationGuardTest extends TestCase
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

    public function testNotBlockedWhenNoMarkerAndNoProbe(): void
    {
        $guard = new ReInstallationGuard($this->markerPath());

        self::assertNull($guard->blockedReason());
        self::assertFalse($guard->isBlocked());
    }

    public function testBlockedByAnExistingMarker(): void
    {
        $marker = $this->markerPath();
        file_put_contents($marker, 'installed');

        $guard = new ReInstallationGuard($marker);

        self::assertSame('marker_present', $guard->blockedReason());
        self::assertTrue($guard->isBlocked());
    }

    public function testBlockedByTheProbeWhenTheMarkerIsAbsent(): void
    {
        $guard = new ReInstallationGuard($this->markerPath(), $this->fixedProbe(true));

        self::assertSame('database_provisioned', $guard->blockedReason());
    }

    public function testNotBlockedWhenTheProbeReportsNotProvisioned(): void
    {
        $guard = new ReInstallationGuard($this->markerPath(), $this->fixedProbe(false));

        self::assertNull($guard->blockedReason());
    }

    public function testTheMarkerShortCircuitsTheProbe(): void
    {
        $marker = $this->markerPath();
        file_put_contents($marker, 'installed');

        $probe = new class () implements ProvisioningProbe {
            public int $calls = 0;

            public function isProvisioned(): bool
            {
                $this->calls++;

                return true;
            }
        };
        $guard = new ReInstallationGuard($marker, $probe);

        self::assertSame('marker_present', $guard->blockedReason());
        self::assertSame(0, $probe->calls, 'the probe must not be consulted once the marker is present');
    }

    public function testMarkInstalledWritesTheMarkerAndThenBlocks(): void
    {
        $marker = $this->markerPath();
        $guard = new ReInstallationGuard($marker);

        self::assertFalse($guard->isBlocked());

        $guard->markInstalled('2026-07-02T00:00:00Z');

        self::assertFileExists($marker);
        self::assertSame('2026-07-02T00:00:00Z', file_get_contents($marker));
        self::assertSame('marker_present', $guard->blockedReason());
    }

    public function testMarkInstalledThrowsWhenTheDirectoryIsMissing(): void
    {
        $guard = new ReInstallationGuard('/no/such/dir/.installed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('directory does not exist');
        $guard->markInstalled();
    }

    private function fixedProbe(bool $provisioned): ProvisioningProbe
    {
        return new class ($provisioned) implements ProvisioningProbe {
            public function __construct(private bool $provisioned)
            {
            }

            public function isProvisioned(): bool
            {
                return $this->provisioned;
            }
        };
    }

    private function markerPath(): string
    {
        $path = sys_get_temp_dir() . '/nene2-marker-' . bin2hex(random_bytes(6)) . '.installed';
        $this->cleanup[] = $path;

        return $path;
    }
}
