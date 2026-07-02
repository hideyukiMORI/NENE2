<?php

declare(strict_types=1);

namespace Nene2\Tests\Install;

use Nene2\Install\DatabaseSchemaApplier;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class DatabaseSchemaApplierTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required to exercise the migration runner.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->removePath($path);
        }

        $this->cleanup = [];
    }

    public function testAppliesPendingMigrations(): void
    {
        [$migrationsDir, $table] = $this->writeCreateTableMigration();
        $config = $this->sqliteConfig($migrationsDir, $this->makeDir());

        (new DatabaseSchemaApplier())->apply($config, 'test');

        self::assertTrue($this->hasTable($config, $table), 'the migration created its table');
        self::assertTrue($this->hasTable($config, 'phinxlog'), 'phinx recorded the migration');
    }

    public function testApplyingIsIdempotent(): void
    {
        [$migrationsDir, $table] = $this->writeCreateTableMigration();
        $config = $this->sqliteConfig($migrationsDir, $this->makeDir());
        $applier = new DatabaseSchemaApplier();

        $applier->apply($config, 'test');
        // A second run has nothing pending and must not error or re-create the table.
        $applier->apply($config, 'test');

        self::assertTrue($this->hasTable($config, $table));
    }

    public function testWrapsAMigrationFailure(): void
    {
        [$migrationsDir] = $this->writeCreateTableMigration();
        $config = $this->sqliteConfig($migrationsDir, $this->makeDir());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Applying database migrations failed');
        (new DatabaseSchemaApplier())->apply($config, 'no_such_environment');
    }

    public function testApplyFromPhpConfigLoadsAndMigrates(): void
    {
        [$migrationsDir, $table] = $this->writeCreateTableMigration();
        $configArray = $this->sqliteConfigArray($migrationsDir, $this->makeDir());
        $configFile = $this->makeDir() . '/phinx.php';
        file_put_contents($configFile, "<?php\n\nreturn " . var_export($configArray, true) . ";\n");

        (new DatabaseSchemaApplier())->applyFromPhpConfig($configFile, 'test');

        self::assertTrue($this->hasTable(new Config($configArray), $table));
    }

    public function testApplyFromPhpConfigRejectsAMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        (new DatabaseSchemaApplier())->applyFromPhpConfig('/no/such/phinx.php');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Inspect the migrated database through a fresh phinx manager sharing the config
     * (a temp-file SQLite, so a second connection sees the committed schema).
     */
    private function hasTable(Config $config, string $table): bool
    {
        $manager = new Manager($config, new ArrayInput([]), new BufferedOutput());

        return $manager->getEnvironment('test')->getAdapter()->hasTable($table);
    }

    /**
     * Write a single valid phinx migration into a fresh temp directory.
     *
     * @return array{0: string, 1: string} [migrationsDir, tableName]
     */
    private function writeCreateTableMigration(): array
    {
        $dir = $this->makeDir();
        $suffix = $this->randomLetters(8);
        $table = 'widget_' . $suffix;
        $class = 'CreateWidget' . ucfirst($suffix);

        $migration = <<<PHP
        <?php

        declare(strict_types=1);

        use Phinx\\Migration\\AbstractMigration;

        final class {$class} extends AbstractMigration
        {
            public function change(): void
            {
                \$this->table('{$table}')
                    ->addColumn('name', 'string', ['null' => false])
                    ->create();
            }
        }
        PHP;

        file_put_contents($dir . '/20260101000001_create_widget_' . $suffix . '.php', $migration);

        return [$dir, $table];
    }

    /**
     * @return array{paths: array{migrations: string}, environments: array<string, mixed>, version_order: string}
     */
    private function sqliteConfigArray(string $migrationsDir, string $dbDir): array
    {
        return [
            'paths' => ['migrations' => $migrationsDir],
            'environments' => [
                'default_environment' => 'test',
                'test' => [
                    'adapter' => 'sqlite',
                    'name' => $dbDir . '/schema',
                ],
            ],
            'version_order' => 'creation',
        ];
    }

    private function sqliteConfig(string $migrationsDir, string $dbDir): Config
    {
        return new Config($this->sqliteConfigArray($migrationsDir, $dbDir));
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . '/nene2-schema-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0777, true));
        $this->cleanup[] = $dir;

        return $dir;
    }

    private function randomLetters(int $count): string
    {
        $bytes = random_bytes(max(1, $count));
        $letters = '';

        for ($i = 0; $i < $count; $i++) {
            $letters .= chr(97 + (ord($bytes[$i]) % 26));
        }

        return $letters;
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
