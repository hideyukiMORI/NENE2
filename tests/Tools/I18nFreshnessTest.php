<?php

declare(strict_types=1);

namespace Nene2\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Covers `tools/i18n-freshness.php` (`composer i18n:check`) — #1627.
 *
 * The script is exercised as a subprocess against fixture trees via `--root`,
 * because the contract CI observes is the exit code plus the finding list. The
 * fixtures are deliberately tiny: what matters is which *kind* of edit produces
 * which severity, not document size.
 */
final class I18nFreshnessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nene2-i18n-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPassesWhenEveryTranslationMatchesItsBaseline(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\nCall `POST /tags`.\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n\n`POST /tags` を呼びます。\n");
        $this->seedBaseline();

        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Translation freshness OK', $out);
    }

    public function testReportsMissingTranslationAsError(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n", ['ja', 'de']);
        $this->seedBaseline(expectedExit: 1);

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[missing]', $out);
        self::assertStringContainsString('docs/fr/howto/add-tag.md', $out);
    }

    public function testFrontmatterOnlyChangeIsNotDrift(): void
    {
        // The regression that motivated hashing the body instead of comparing
        // commit dates: two bulk commits added frontmatter to every guide and
        // would otherwise have marked 245 translation pairs stale (#1627).
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\nCall `POST /tags`.\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        $this->write('docs/howto/add-tag.md', <<<'MD'
            ---
            title: "Add a tag"
            category: api-design
            tags: [tags]
            difficulty: beginner
            ---

            # Add a tag

            Call `POST /tags`.
            MD);

        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
    }

    public function testProseOnlyChangeIsAWarningNotAnError(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\nCall the endpoint.\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        // A typo fix must not turn five locales red.
        $this->write('docs/howto/add-tag.md', "# Add a tag\n\nCall the endpoint now.\n");

        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('[stale-prose]', $out);
        self::assertStringContainsString('0 error(s), 5 warning(s)', $out);
    }

    public function testChangedCodeBlockIsAStructuralError(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\n```php\n\$tag->check(): bool;\n```\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        $this->write('docs/howto/add-tag.md', "# Add a tag\n\n```php\n\$tag->check(): TagStatus;\n```\n");

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[stale-structure]', $out);
    }

    public function testChangedTableRowIsAStructuralError(): void
    {
        $this->writeGuide('reference/env.md', "# Env\n\n| Key | Default |\n|---|---|\n| DB_PORT | 3306 |\n");
        $this->writeTranslations('reference/env.md', "# 環境変数\n");
        $this->seedBaseline();

        $this->write('docs/reference/env.md', "# Env\n\n| Key | Default |\n|---|---|\n| DB_PORT | 5432 |\n");

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[stale-structure]', $out);
    }

    public function testChangedLinkTargetIsAStructuralError(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\nSee [config](./configuration.md).\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        $this->write('docs/howto/add-tag.md', "# Add a tag\n\nSee [config](./settings.md).\n");

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[stale-structure]', $out);
    }

    public function testTranslationWithoutAnEnglishOriginalIsAnOrphan(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        // Left over from a rename on the English side.
        $this->write('docs/ja/howto/old-name.md', "# 旧タイトル\n");

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[orphan]', $out);
        self::assertStringContainsString('docs/ja/howto/old-name.md', $out);
    }

    public function testUnmanagedPairIsAnErrorRatherThanSilentlyIgnored(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        // A new guide translated but never recorded: it must not pass unnoticed.
        $this->writeGuide('howto/add-note.md', "# Add a note\n");
        $this->writeTranslations('howto/add-note.md', "# ノートを追加する\n");

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[unmanaged]', $out);
    }

    public function testExcludedAndOutOfScopeFilesAreNotReported(): void
    {
        // docs/development/ is English-only by policy; howto/README.md is a
        // build artifact of `composer howto:index`. Neither is a gap.
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->seedBaseline();

        $this->write('docs/development/coding-standards.md', "# Coding standards\n");
        $this->write('docs/howto/README.md', "# Index\n");
        $this->write('docs/howto/by-tag.md', "# By tag\n");

        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('coding-standards', $out);
        self::assertStringNotContainsString('README.md', $out);
    }

    public function testBootstrapRefusesToRunWithoutGit(): void
    {
        // Silently falling back to current hashes would bless every stale
        // translation — the exact failure this checker exists to prevent.
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");

        [$code, $out] = $this->runScript('--bootstrap');

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('needs a working git', $out);
    }

    public function testOnlyRestrictsTheRewriteToTheNamedDocument(): void
    {
        // A staged burn-down updates one document at a time. Without --only the
        // rewrite would bless the documents that PR never touched.
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n\n```php\nold();\n```\n");
        $this->writeGuide('howto/add-note.md', "# Add a note\n\n```php\nold();\n```\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n");
        $this->writeTranslations('howto/add-note.md', "# ノートを追加する\n");
        $this->seedBaseline();

        $this->write('docs/howto/add-tag.md', "# Add a tag\n\n```php\nfixed();\n```\n");
        $this->write('docs/howto/add-note.md', "# Add a note\n\n```php\nalsoChanged();\n```\n");

        [$code, $out] = $this->runScript('--write-baseline', '--only=howto/add-tag.md');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('updated for 1 document(s)', $out);

        [$code, $out] = $this->check();

        self::assertSame(1, $code, $out);
        self::assertStringNotContainsString('add-tag.md', $out);
        self::assertStringContainsString('add-note.md', $out);
    }

    public function testOnlyWithoutWriteBaselineIsAUsageError(): void
    {
        [$code, $out] = $this->runScript('--only=howto/add-tag.md');

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('--only only applies', $out);
    }

    public function testRejectsUnknownArguments(): void
    {
        [$code, $out] = $this->runScript('--unknown');

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('Unknown argument', $out);
    }

    public function testJsonFormatReportsCountsAndFindings(): void
    {
        $this->writeGuide('howto/add-tag.md', "# Add a tag\n");
        $this->writeTranslations('howto/add-tag.md', "# タグを追加する\n", ['ja']);
        $this->seedBaseline(expectedExit: 1);

        [$code, $out] = $this->runScript('--format=json');

        self::assertSame(1, $code, $out);
        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['ok']);
        self::assertSame(1, $decoded['pairs']);
        self::assertSame(4, $decoded['errors']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array{0: int, 1: string}
     */
    private function check(string ...$extra): array
    {
        return $this->runScript(...$extra);
    }

    /**
     * Seeding still reports gaps: a missing translation is a real gap whether or
     * not you are writing the baseline, so those fixtures expect exit 1 here.
     */
    private function seedBaseline(int $expectedExit = 0): void
    {
        [$code, $out] = $this->runScript('--write-baseline');
        self::assertSame($expectedExit, $code, $out);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string ...$extra): array
    {
        $command = sprintf(
            '%s %s --root=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/tools/i18n-freshness.php'),
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

    private function writeGuide(string $relative, string $contents): void
    {
        $this->write('docs/' . $relative, $contents);
    }

    /** @param list<string> $locales */
    private function writeTranslations(string $relative, string $contents, ?array $locales = null): void
    {
        foreach ($locales ?? ['ja', 'de', 'fr', 'zh', 'pt-br'] as $locale) {
            $this->write('docs/' . $locale . '/' . $relative, $contents);
        }
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
