<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\ZipEntrySafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZipEntrySafetyTest extends TestCase
{
    #[DataProvider('escapeCases')]
    public function testEscapesRoot(string $entry, bool $expected): void
    {
        self::assertSame($expected, ZipEntrySafety::escapesRoot($entry));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function escapeCases(): iterable
    {
        yield 'plain file' => ['composer.json', false];
        yield 'nested file' => ['src/Http/App.php', false];
        yield 'empty' => ['', false];
        yield 'root slash' => ['/', false];
        yield 'leading dot-slash is safe' => ['./src/App.php', false];
        yield 'absolute path' => ['/etc/passwd', true];
        yield 'windows drive letter' => ['C:\\Windows\\system32', true];
        yield 'parent traversal' => ['../evil.txt', true];
        yield 'nested traversal' => ['src/../../evil.txt', true];
        yield 'backslash traversal' => ['src\\..\\..\\evil', true];
        yield 'dotdot mid-path' => ['a/../b', true];
    }

    #[DataProvider('topCases')]
    public function testTopSegment(string $entry, string $expected): void
    {
        self::assertSame($expected, ZipEntrySafety::topSegment($entry));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function topCases(): iterable
    {
        yield 'file at root' => ['composer.json', 'composer.json'];
        yield 'nested' => ['src/Http/App.php', 'src'];
        yield 'leading slash stripped' => ['/vendor/autoload.php', 'vendor'];
        yield 'backslash normalised' => ['public_html\\index.php', 'public_html'];
        yield 'trailing slash directory' => ['database/', 'database'];
    }
}
