<?php

declare(strict_types=1);

namespace Nene2\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Covers `tools/validate-version.php` (`composer version:check`) — in particular
 * the README "current version" claim added in #1623.
 *
 * The script is exercised as a subprocess against fixture trees via `--root`,
 * because that is how it fails in practice: a non-zero exit code inside
 * `composer check`. Asserting on the exit code and the stderr text keeps the
 * regression honest about the part CI actually observes.
 */
final class ValidateVersionTest extends TestCase
{
    private const VERSION = '1.11.0';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nene2-version-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);

        // A consistent baseline tree; each test perturbs only what it is about.
        $this->write('src/FrameworkInfo.php', sprintf(
            "<?php\nfinal class FrameworkInfo { public const string VERSION = '%s'; }\n",
            self::VERSION,
        ));
        $this->write('docs/openapi/openapi.yaml', sprintf(
            "openapi: 3.1.0\ninfo:\n  title: Fixture\n  version: '%s'\n",
            self::VERSION,
        ));
        $this->write('CHANGELOG.md', sprintf(
            "# Changelog\n\n## [Unreleased]\n\n## [%s] — 2026-07-14\n",
            self::VERSION,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPassesWhenReadmeClaimsTheCurrentVersion(): void
    {
        $this->write('README.md', sprintf(
            "# Fixture\n\n- **140+ releases** (current: `v%s`) and 780+ merged pull requests.\n",
            self::VERSION,
        ));

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Version consistency OK', $output);
    }

    public function testFailsWhenReadmeClaimsAnOlderVersion(): void
    {
        // The exact drift #1623 was filed for: the release moved, the README did not.
        $this->write('README.md', "# Fixture\n\n- **140+ releases** (current: `v1.10.0`) and 740+ merged pull requests.\n");

        [$code, $output] = $this->runScript();

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('README.md:3', $output);
        self::assertStringContainsString("claims current version '1.10.0'", $output);
        self::assertStringContainsString("FrameworkInfo::VERSION is '1.11.0'", $output);
    }

    public function testIgnoresHistoricalVersionReferencesWithoutACurrentClaim(): void
    {
        // README.md:249 in this repository points at the v0.1.1 field-trial fork.
        // A version literal is only a claim about *now* when the line says so.
        $this->write('README.md', <<<'MD'
            # Fixture

            Forked from **`v0.1.1`** with exhibition-shaped read-only APIs.
            Available since v1.6.0; the installer toolkit landed in v1.6.0 too.
            MD);

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
    }

    public function testReportsEveryDriftedClaimOnALineAtOnce(): void
    {
        $this->write('README.md', "# Fixture\n\nlatest: v1.9.0 (previously v1.8.2)\n");

        [$code, $output] = $this->runScript();

        self::assertSame(1, $code, $output);
        self::assertStringContainsString("claims current version '1.9.0'", $output);
        self::assertStringContainsString("claims current version '1.8.2'", $output);
    }

    public function testPassesWhenTheProjectHasNoReadme(): void
    {
        // No README means no claim to contradict — the other checks still apply.
        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
    }

    public function testStillFailsOnChangelogDriftWithACleanReadme(): void
    {
        // Guards against the README check shadowing the pre-existing checks.
        $this->write('README.md', sprintf("# Fixture\n\ncurrent: `v%s`\n", self::VERSION));
        $this->write('CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.10.0] — 2026-07-10\n");

        [$code, $output] = $this->runScript();

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('CHANGELOG.md latest released version', $output);
    }

    public function testRejectsUnknownArguments(): void
    {
        [$code, $output] = $this->runScript('--unknown');

        self::assertSame(2, $code, $output);
        self::assertStringContainsString('Unknown argument', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string ...$extra): array
    {
        $command = sprintf(
            '%s %s --root=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/tools/validate-version.php'),
            escapeshellarg($this->root),
        );

        foreach ($extra as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
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
