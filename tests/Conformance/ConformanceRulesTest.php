<?php

declare(strict_types=1);

namespace Nene2\Tests\Conformance;

use Nene2\Conformance\Baseline;
use Nene2\Conformance\ConformanceRunner;
use Nene2\Conformance\Finding;
use Nene2\Conformance\Rule\CheckSelfRegistrationRule;
use Nene2\Conformance\Rule\DependencyBranchPinRule;
use Nene2\Conformance\Rule\JwtDefaultSecretRule;
use Nene2\Conformance\Rule\RawClockRule;
use Nene2\Conformance\Severity;
use PHPUnit\Framework\TestCase;

final class ConformanceRulesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nene2-conformance-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // --- D1 -----------------------------------------------------------------

    public function testD1FlagsHardCodedDevSecretLiteral(): void
    {
        $this->writeSrc('Auth/TokenService.php', <<<'PHP'
            <?php
            namespace App\Auth;
            final class TokenService {
                public function secret(): string {
                    return getenv('JWT_SECRET') ?: 'acme-dev-secret';
                }
            }
            PHP);

        $ruleIds = $this->ruleIds((new JwtDefaultSecretRule())->check($this->root));
        self::assertContains('D1', $ruleIds);
    }

    public function testD1IgnoresEnvNamesProseAndArrayKeys(): void
    {
        $this->writeSrc('Auth/Clean.php', <<<'PHP'
            <?php
            namespace App\Auth;
            final class Clean {
                // A sentence mentioning the development-secret opt-in must not trip.
                private const ENV = 'NENE2_ALLOW_DEV_SECRET';
                public function note(): string { return 'the development-secret opt-in is off'; }
                public function cfg(array $c): mixed { return $c['secret-key']; }
            }
            PHP);

        self::assertSame([], (new JwtDefaultSecretRule())->check($this->root));
    }

    // --- D2 -----------------------------------------------------------------

    public function testD2FlagsFeatureBranchPinInComposerJson(): void
    {
        $this->write('composer.json', json_encode([
            'require' => ['hideyukimori/nene2' => 'dev-feat/new-thing'],
        ], JSON_THROW_ON_ERROR));

        $findings = (new DependencyBranchPinRule())->check($this->root);
        self::assertCount(1, $findings);
        self::assertSame('composer.json', $findings[0]->file);
    }

    public function testD2FlagsFeatureBranchInComposerLock(): void
    {
        $this->write('composer.json', json_encode([
            'require' => ['hideyukimori/nene2' => '^1.8'],
        ], JSON_THROW_ON_ERROR));
        $this->write('composer.lock', json_encode([
            'packages' => [['name' => 'hideyukimori/nene2', 'version' => 'dev-fix/hotpatch']],
        ], JSON_THROW_ON_ERROR));

        $findings = (new DependencyBranchPinRule())->check($this->root);
        self::assertCount(1, $findings);
        self::assertSame('composer.lock', $findings[0]->file);
    }

    public function testD2AcceptsTagsAndMainline(): void
    {
        $this->write('composer.json', json_encode([
            'require' => ['hideyukimori/nene2' => '^1.8', 'foo/bar' => 'dev-main'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], (new DependencyBranchPinRule())->check($this->root));
    }

    // --- D3 -----------------------------------------------------------------

    public function testD3FlagsCheckWithoutConformance(): void
    {
        $this->write('composer.json', json_encode([
            'scripts' => ['check' => ['@test', '@analyse']],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, (new CheckSelfRegistrationRule())->check($this->root));
    }

    public function testD3PassesWhenConformanceWired(): void
    {
        $this->write('composer.json', json_encode([
            'scripts' => ['check' => ['@test', '@conformance']],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], (new CheckSelfRegistrationRule())->check($this->root));
    }

    // --- D4 -----------------------------------------------------------------

    public function testD4FlagsRawTimeCall(): void
    {
        $this->writeSrc('Window.php', <<<'PHP'
            <?php
            namespace App;
            final class Window { public function now(): int { return time(); } }
            PHP);

        $findings = (new RawClockRule())->check($this->root);
        self::assertCount(1, $findings);
        self::assertSame('D4', $findings[0]->ruleId);
    }

    public function testD4IgnoresClockImplFormattingAndComments(): void
    {
        // Clock implementations are the sanctioned source of "now".
        $this->writeSrc('Clock.php', <<<'PHP'
            <?php
            namespace App;
            interface ClockInterface { public function now(): \DateTimeImmutable; }
            final class SystemClock implements ClockInterface {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now'); }
            }
            PHP);
        // date() with an explicit timestamp is formatting, not a clock read.
        $this->writeSrc('Fmt.php', <<<'PHP'
            <?php
            namespace App;
            final class Fmt {
                // time() in a comment should never trip.
                public function f(int $ts): string { return date('Y-m-d', $ts); }
            }
            PHP);

        self::assertSame([], (new RawClockRule())->check($this->root));
    }

    public function testD4FlagsNewDateTimeNow(): void
    {
        $this->writeSrc('Stamp.php', <<<'PHP'
            <?php
            namespace App;
            final class Stamp { public function at(): \DateTimeImmutable { return new \DateTimeImmutable('now'); } }
            PHP);

        self::assertCount(1, (new RawClockRule())->check($this->root));
    }

    // --- Baseline / allowlist / inline ignore -------------------------------

    public function testAllowlistRequiresReason(): void
    {
        $this->write('conformance.baseline.json', json_encode([
            'allow' => [['rule' => 'D4', 'file' => 'src/Window.php']],
        ], JSON_THROW_ON_ERROR));

        $baseline = Baseline::load($this->root . '/conformance.baseline.json');
        self::assertNotSame([], $baseline->validationErrors());
    }

    public function testAllowlistSuppressesWithReason(): void
    {
        $this->writeSrc('Window.php', <<<'PHP'
            <?php
            namespace App;
            final class Window { public function now(): int { return time(); } }
            PHP);
        $this->write('conformance.baseline.json', json_encode([
            'allow' => [['rule' => 'D4', 'file' => 'src/Window.php', 'reason' => 'legacy primitive, tracked in #123']],
        ], JSON_THROW_ON_ERROR));

        $baseline = Baseline::load($this->root . '/conformance.baseline.json');
        self::assertSame([], $baseline->validationErrors());

        $runner = new ConformanceRunner([new RawClockRule()]);
        $result = $runner->run($this->root, $baseline);
        self::assertSame(0, $result->exitCode());
        self::assertSame(1, $result->suppressed);
    }

    public function testBaselineIgnoreSnapshotSuppressesByCount(): void
    {
        $finding = new Finding('D4', Severity::Error, 'src/Window.php', 10, 'Raw current-time read time().');
        $this->write('conformance.baseline.json', json_encode([
            'ignore' => [['rule' => 'D4', 'file' => 'src/Window.php', 'message' => 'Raw current-time read time().', 'count' => 1]],
        ], JSON_THROW_ON_ERROR));

        $baseline = Baseline::load($this->root . '/conformance.baseline.json');
        self::assertTrue($baseline->suppresses($finding));
        // Budget of 1 is now exhausted.
        self::assertFalse($baseline->suppresses($finding));
    }

    public function testInlineIgnoreSuppressesFinding(): void
    {
        $this->writeSrc('Window.php', <<<'PHP'
            <?php
            namespace App;
            final class Window {
                // conformance:ignore D4 measured elsewhere
                public function now(): int { return time(); }
            }
            PHP);

        $runner = new ConformanceRunner([new RawClockRule()]);
        $result = $runner->run($this->root, Baseline::empty());
        self::assertSame([], $result->findings);
        self::assertSame(1, $result->suppressed);
    }

    public function testWriteBaselineCapturesCurrentFindings(): void
    {
        $this->writeSrc('Window.php', <<<'PHP'
            <?php
            namespace App;
            final class Window { public function now(): int { return time(); } }
            PHP);

        $runner = new ConformanceRunner([new RawClockRule()]);
        $data = $runner->buildBaseline($this->root, Baseline::empty());
        self::assertSame(1, $data['version']);
        self::assertCount(1, $data['ignore']);
        self::assertSame('D4', $data['ignore'][0]['rule']);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private function ruleIds(array $findings): array
    {
        return array_map(static fn (Finding $f): string => $f->ruleId, $findings);
    }

    private function writeSrc(string $relative, string $contents): void
    {
        $this->write('src/' . $relative, $contents);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
