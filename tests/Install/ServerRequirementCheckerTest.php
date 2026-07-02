<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\ServerRequirementChecker;
use Nene2\Install\ServerRequirements;
use Nene2\Install\SystemProbe;
use PHPUnit\Framework\TestCase;

final class ServerRequirementCheckerTest extends TestCase
{
    public function testReportsPhpVersionSatisfiedAndTooOld(): void
    {
        $ok = (new ServerRequirementChecker($this->probe(phpVersion: '8.4.5')))
            ->check(new ServerRequirements('8.4.0'));
        self::assertCount(1, $ok);
        self::assertSame(ServerRequirementChecker::REQUIREMENT_PHP, $ok[0]->requirement);
        self::assertTrue($ok[0]->satisfied);
        self::assertSame(['php_ok'], $ok[0]->reasonCodes);

        $tooOld = (new ServerRequirementChecker($this->probe(phpVersion: '8.3.9')))
            ->check(new ServerRequirements('8.4.0'));
        self::assertFalse($tooOld[0]->satisfied);
        self::assertSame(['php_too_old'], $tooOld[0]->reasonCodes);
    }

    public function testReportsExtensionsLoadedAndMissingPerExtension(): void
    {
        $checks = (new ServerRequirementChecker($this->probe(extensions: ['pdo_mysql'])))
            ->check(new ServerRequirements('8.0.0', requiredExtensions: ['pdo_mysql', 'gd']));

        // [php, pdo_mysql, gd]
        self::assertSame('pdo_mysql', $checks[1]->target);
        self::assertTrue($checks[1]->satisfied);
        self::assertSame(['extension_loaded'], $checks[1]->reasonCodes);

        self::assertSame('gd', $checks[2]->target);
        self::assertFalse($checks[2]->satisfied);
        self::assertSame(['extension_missing'], $checks[2]->reasonCodes);
    }

    public function testWritablePathThatExistsAndIsWritable(): void
    {
        $checks = (new ServerRequirementChecker($this->probe(exists: ['/app/var'], writable: ['/app/var'])))
            ->check(new ServerRequirements('8.0.0', writablePaths: ['/app/var']));

        self::assertTrue($checks[1]->satisfied);
        self::assertSame(['writable'], $checks[1]->reasonCodes);
    }

    public function testWritablePathThatExistsButIsNotWritable(): void
    {
        $checks = (new ServerRequirementChecker($this->probe(exists: ['/app/var'])))
            ->check(new ServerRequirements('8.0.0', writablePaths: ['/app/var']));

        self::assertFalse($checks[1]->satisfied);
        self::assertSame(['not_writable'], $checks[1]->reasonCodes);
    }

    public function testMissingPathIsCreatableWhenParentIsWritable(): void
    {
        $checks = (new ServerRequirementChecker($this->probe(writable: ['/app'])))
            ->check(new ServerRequirements('8.0.0', writablePaths: ['/app/var']));

        self::assertTrue($checks[1]->satisfied);
        self::assertSame(['creatable'], $checks[1]->reasonCodes);
    }

    public function testMissingPathIsNotWritableWhenParentIsNotWritable(): void
    {
        $checks = (new ServerRequirementChecker($this->probe()))
            ->check(new ServerRequirements('8.0.0', writablePaths: ['/app/var']));

        self::assertFalse($checks[1]->satisfied);
        self::assertSame(['not_writable'], $checks[1]->reasonCodes);
    }

    public function testReportsRequiredFilePresentAndMissing(): void
    {
        $present = (new ServerRequirementChecker($this->probe(exists: ['/app/vendor/autoload.php'])))
            ->check(new ServerRequirements('8.0.0', requiredFiles: ['/app/vendor/autoload.php']));
        self::assertTrue($present[1]->satisfied);
        self::assertSame(['present'], $present[1]->reasonCodes);

        $missing = (new ServerRequirementChecker($this->probe()))
            ->check(new ServerRequirements('8.0.0', requiredFiles: ['/app/vendor/autoload.php']));
        self::assertFalse($missing[1]->satisfied);
        self::assertSame(['missing'], $missing[1]->reasonCodes);
    }

    public function testAllSatisfiedAndUnmetHelpers(): void
    {
        $checks = (new ServerRequirementChecker($this->probe(phpVersion: '8.4.0', extensions: ['json'])))
            ->check(new ServerRequirements('8.4.0', requiredExtensions: ['json', 'imagick']));

        self::assertFalse(ServerRequirementChecker::allSatisfied($checks));

        $unmet = ServerRequirementChecker::unmet($checks);
        self::assertCount(1, $unmet);
        self::assertSame('imagick', $unmet[0]->target);

        $allOk = (new ServerRequirementChecker($this->probe(phpVersion: '8.4.0', extensions: ['json'])))
            ->check(new ServerRequirements('8.4.0', requiredExtensions: ['json']));
        self::assertTrue(ServerRequirementChecker::allSatisfied($allOk));
        self::assertSame([], ServerRequirementChecker::unmet($allOk));
    }

    public function testDefaultProbeReadsTheLiveRuntime(): void
    {
        $checks = (new ServerRequirementChecker())->check(new ServerRequirements(
            minPhpVersion: '5.6.0',
            requiredExtensions: ['json', 'nene_missing_extension_xyz'],
            writablePaths: [sys_get_temp_dir()],
            requiredFiles: [__FILE__],
        ));

        self::assertTrue($checks[0]->satisfied, 'live PHP is newer than 5.6');
        self::assertTrue($checks[1]->satisfied, 'json is always loaded');
        self::assertFalse($checks[2]->satisfied, 'the fake extension is missing');
        self::assertTrue($checks[3]->satisfied, 'the temp dir is writable');
        self::assertTrue($checks[4]->satisfied, 'this test file exists');
    }

    /**
     * @param list<string> $extensions Loaded extensions.
     * @param list<string> $writable   Writable paths.
     * @param list<string> $exists     Existing paths.
     */
    private function probe(
        string $phpVersion = '8.4.0',
        array $extensions = [],
        array $writable = [],
        array $exists = [],
    ): SystemProbe {
        return new class ($phpVersion, $extensions, $writable, $exists) implements SystemProbe {
            /**
             * @param list<string> $extensions
             * @param list<string> $writable
             * @param list<string> $exists
             */
            public function __construct(
                private string $phpVersion,
                private array $extensions,
                private array $writable,
                private array $exists,
            ) {
            }

            public function phpVersion(): string
            {
                return $this->phpVersion;
            }

            public function extensionLoaded(string $extension): bool
            {
                return in_array($extension, $this->extensions, true);
            }

            public function isWritable(string $path): bool
            {
                return in_array($path, $this->writable, true);
            }

            public function exists(string $path): bool
            {
                return in_array($path, $this->exists, true);
            }
        };
    }
}
