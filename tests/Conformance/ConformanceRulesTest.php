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

    public function testD1ExemptsDevSecretConstantFedToGuardedResolver(): void
    {
        // Canonical 2026-07-05 fail-close pattern (all 11 migrated products): the
        // dev-secret literal only feeds GuardedJwtSecretResolver::fromConfig() via
        // the constant it initialises, so the resolver (ADR 0013) guards it.
        $this->writeSrc('Auth/AuthServiceProvider.php', <<<'PHP'
            <?php
            namespace App\Auth;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class AuthServiceProvider {
                private const DEFAULT_DEV_SECRET = 'nene-invoice-dev-secret';
                public function verifier(object $config): string {
                    return GuardedJwtSecretResolver::fromConfig($config, self::DEFAULT_DEV_SECRET);
                }
            }
            PHP);

        self::assertSame([], (new JwtDefaultSecretRule())->check($this->root));
    }

    public function testD1ExemptsLiteralPassedDirectlyToGuardedResolver(): void
    {
        $this->writeSrc('Auth/Direct.php', <<<'PHP'
            <?php
            namespace App\Auth;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class Direct {
                public function verifier(object $config): string {
                    return GuardedJwtSecretResolver::fromConfig($config, 'acme-dev-secret');
                }
            }
            PHP);

        self::assertSame([], (new JwtDefaultSecretRule())->check($this->root));
    }

    public function testD1StillFlagsNakedSecretDespiteGuardedResolverInSameFile(): void
    {
        // Over-exemption guard: the guarded constant is exempt, but a truly naked
        // fallback in the *same* file — never fed to the resolver — stays flagged.
        $this->writeSrc('Auth/Mixed.php', <<<'PHP'
            <?php
            namespace App\Auth;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class Mixed {
                private const DEFAULT_DEV_SECRET = 'guarded-dev-secret';
                public function ok(object $config): string {
                    return GuardedJwtSecretResolver::fromConfig($config, self::DEFAULT_DEV_SECRET);
                }
                public function leak(): string {
                    return getenv('JWT_SECRET') ?: 'naked-dev-secret';
                }
            }
            PHP);

        $findings = (new JwtDefaultSecretRule())->check($this->root);
        self::assertCount(1, $findings);
        self::assertSame('D1', $findings[0]->ruleId);
        self::assertStringContainsString('naked-dev-secret', $findings[0]->message);
    }

    public function testD1ExemptsNamedDevSecretConstructorArgument(): void
    {
        // nene-serve's real shape (src/Http/RuntimeServiceProvider.php): a custom
        // secret env key means the product drives the resolver's constructor
        // directly with named arguments instead of the fromConfig() convenience
        // path; the dev-secret constant still only feeds the fail-closed resolver.
        $this->writeSrc('Http/RuntimeServiceProvider.php', <<<'PHP'
            <?php
            namespace App\Http;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class RuntimeServiceProvider {
                private const string JWT_SECRET_ENV_KEY = 'NENE_SERVE_JWT_SECRET';
                private const string DEFAULT_DEV_SECRET = 'nene-serve-dev-secret';
                public function secret(object $config): string {
                    $configuredSecret = $_SERVER[self::JWT_SECRET_ENV_KEY] ?? '';
                    return (new GuardedJwtSecretResolver(
                        configuredSecret: is_string($configuredSecret) ? $configuredSecret : '',
                        environment: $config->environment,
                        allowDevSecret: $config->allowDevSecret,
                        devSecret: self::DEFAULT_DEV_SECRET,
                        secretEnvName: self::JWT_SECRET_ENV_KEY,
                    ))->resolve();
                }
            }
            PHP);

        self::assertSame([], (new JwtDefaultSecretRule())->check($this->root));
    }

    public function testD1ExemptsPositionalDevSecretConstructorArgument(): void
    {
        // Fourth positional constructor argument is the devSecret parameter; a
        // literal passed there is guarded by the resolver just like fromConfig().
        $this->writeSrc('Auth/PositionalCtor.php', <<<'PHP'
            <?php
            namespace App\Auth;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class PositionalCtor {
                public function secret(object $config): string {
                    return (new GuardedJwtSecretResolver(
                        getenv('APP_JWT_SECRET') ?: '',
                        $config->environment,
                        $config->allowDevSecret,
                        'acme-dev-secret',
                    ))->resolve();
                }
            }
            PHP);

        self::assertSame([], (new JwtDefaultSecretRule())->check($this->root));
    }

    public function testD1StillFlagsNakedSecretDespiteGuardedConstructorInSameFile(): void
    {
        // Over-exemption guard for the constructor form: only the literal feeding
        // the resolver's devSecret slot is exempt — a naked fallback in the same
        // file, and a dev-secret-shaped literal in a *different* constructor
        // argument, both stay flagged.
        $this->writeSrc('Auth/CtorMixed.php', <<<'PHP'
            <?php
            namespace App\Auth;
            use Nene2\Auth\GuardedJwtSecretResolver;
            final class CtorMixed {
                private const string DEFAULT_DEV_SECRET = 'guarded-dev-secret';
                public function ok(object $config): string {
                    return (new GuardedJwtSecretResolver(
                        configuredSecret: '',
                        environment: $config->environment,
                        allowDevSecret: $config->allowDevSecret,
                        devSecret: self::DEFAULT_DEV_SECRET,
                    ))->resolve();
                }
                public function bad(object $config): string {
                    return (new GuardedJwtSecretResolver(
                        configuredSecret: 'hardcoded-dev-secret',
                        environment: $config->environment,
                        allowDevSecret: $config->allowDevSecret,
                        devSecret: null,
                    ))->resolve();
                }
                public function leak(): string {
                    return getenv('JWT_SECRET') ?: 'naked-dev-secret';
                }
            }
            PHP);

        $findings = (new JwtDefaultSecretRule())->check($this->root);
        self::assertCount(2, $findings);
        self::assertStringContainsString('hardcoded-dev-secret', $findings[0]->message);
        self::assertStringContainsString('naked-dev-secret', $findings[1]->message);
    }

    public function testConformanceCliLoadsConsumerAutoloaderNotFrameworkVendor(): void
    {
        // Regression for the fan-out blocker: the CLI must require the *consumer's*
        // vendor/autoload.php (resolved from --root), not `dirname(__DIR__)/vendor`
        // (NENE2's own vendor). Simulate a flat consumer install where the script
        // lives at vendor/hideyukimori/nene2/tools/ (so dirname(__DIR__) has NO
        // vendor/) while the consumer root carries the flat autoloader.
        $frameworkRoot = dirname(__DIR__, 2);
        $frameworkAutoload = $frameworkRoot . '/vendor/autoload.php';

        if (!is_file($frameworkAutoload)) {
            self::markTestSkipped('framework vendor autoloader unavailable');
        }

        $nestedTools = $this->root . '/vendor/hideyukimori/nene2/tools';
        mkdir($nestedTools, 0o777, true);
        copy($frameworkRoot . '/tools/conformance.php', $nestedTools . '/conformance.php');

        // Flat consumer autoloader shim standing in for Composer's, which registers
        // the Nene2\ PSR-4 prefix (incl. Nene2\Conformance\*).
        $this->write('vendor/autoload.php', sprintf(
            "<?php require %s;\n",
            var_export($frameworkAutoload, true),
        ));
        $this->write('composer.json', json_encode([
            'scripts' => ['check' => ['@conformance']],
        ], JSON_THROW_ON_ERROR));

        $command = sprintf(
            '%s %s --root=%s --format=json 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($nestedTools . '/conformance.php'),
            escapeshellarg($this->root),
        );

        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $stdout = implode("\n", $output);

        self::assertNotSame(255, $code, 'CLI fatally errored (autoload) on a flat consumer vendor: ' . $stdout);
        self::assertJson($stdout);

        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('ok', $decoded);
        self::assertTrue($decoded['ok']);
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
