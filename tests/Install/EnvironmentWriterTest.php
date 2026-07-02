<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Dotenv\Dotenv;
use Nene2\Install\EnvironmentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentWriterTest extends TestCase
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

    public function testSerializesSafeValuesBare(): void
    {
        $env = (new EnvironmentWriter())->serialize([
            'APP_ENV' => 'production',
            'DB_PORT' => '3306',
            'BASE_DOMAIN' => 'records.example.com',
        ]);

        self::assertSame(
            "APP_ENV=production\nDB_PORT=3306\nBASE_DOMAIN=records.example.com\n",
            $env,
        );
    }

    public function testSerializesAnEmptyMapAsAnEmptyString(): void
    {
        self::assertSame('', (new EnvironmentWriter())->serialize([]));
    }

    #[DataProvider('awkwardValues')]
    public function testRoundTripsAwkwardValuesThroughPhpdotenv(string $value): void
    {
        $content = (new EnvironmentWriter())->serialize(['DB_PASSWORD' => $value]);

        $parsed = Dotenv::parse($content);

        self::assertSame($value, $parsed['DB_PASSWORD']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'plain' => ['simplepass'];
        yield 'at and digits' => ['p@ssw0rd'];
        yield 'space' => ['has space'];
        yield 'hash' => ['has#hash'];
        yield 'double quote' => ['has"quote'];
        yield 'apostrophe' => ["has'apostrophe"];
        yield 'dollar' => ['has$dollar'];
        yield 'braced var' => ['${HOME}'];
        yield 'backslash' => ['back\\slash'];
        yield 'trailing backslash' => ['ends-with\\'];
        yield 'semicolon' => ['semi;colon'];
        yield 'equals' => ['a=b=c'];
        yield 'tab' => ["col1\tcol2"];
        yield 'everything' => ['mix "a" $b \\c #d = e'];
        yield 'multibyte' => ['パス春ワード'];
        yield 'empty' => [''];
    }

    public function testWritesAtomicallyWithRestrictedPermissions(): void
    {
        $path = $this->tempPath();

        (new EnvironmentWriter())->write($path, [
            'APP_ENV' => 'production',
            'DB_PASSWORD' => 'p @ss "w" $x',
        ]);

        self::assertFileExists($path);
        self::assertSame(0640, fileperms($path) & 0777);

        $content = file_get_contents($path);
        self::assertNotFalse($content);
        $parsed = Dotenv::parse($content);
        self::assertSame('production', $parsed['APP_ENV']);
        self::assertSame('p @ss "w" $x', $parsed['DB_PASSWORD']);
    }

    public function testWriteToAMissingDirectoryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('directory does not exist');
        (new EnvironmentWriter())->write('/no/such/dir/.env', ['APP_ENV' => 'production']);
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $key): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid environment key');
        (new EnvironmentWriter())->serialize([$key => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'leading digit' => ['1ABC'];
        yield 'dash' => ['HAS-DASH'];
        yield 'space' => ['HAS SPACE'];
        yield 'dot' => ['HAS.DOT'];
        yield 'empty' => [''];
    }

    #[DataProvider('unserialisableValues')]
    public function testRejectsValuesWithLineBreaksOrNullBytes(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('line break or null byte');
        (new EnvironmentWriter())->serialize(['KEY' => $value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unserialisableValues(): iterable
    {
        yield 'newline' => ["line1\nline2"];
        yield 'carriage return' => ["line1\rline2"];
        yield 'null byte' => ["a\0b"];
    }

    public function testGenerateSecretProducesDistinctLowercaseHex(): void
    {
        $a = EnvironmentWriter::generateSecret();
        $b = EnvironmentWriter::generateSecret();

        self::assertSame(64, strlen($a), '32 bytes -> 64 hex chars');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b);
        self::assertSame(16, strlen(EnvironmentWriter::generateSecret(8)));
    }

    public function testGenerateSecretRejectsNonPositiveByteCount(): void
    {
        $this->expectException(RuntimeException::class);
        EnvironmentWriter::generateSecret(0);
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/nene2-env-' . bin2hex(random_bytes(6)) . '.env';
        $this->cleanup[] = $path;

        return $path;
    }
}
