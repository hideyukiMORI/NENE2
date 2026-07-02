<?php

declare(strict_types=1);

namespace Nene2\Install;

use Phinx\Config\Config;
use Phinx\Config\ConfigInterface;
use Phinx\Migration\Manager;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Applies a product's database schema during installation by running its pending
 * phinx migrations — programmatically, via phinx's {@see Manager} API.
 *
 * Shared hosting has no CLI, so migrations cannot be run with `phinx migrate`; this
 * drives the same engine in-process instead. Migrations (not a dumped SQL file) are the
 * source of truth for the schema, so a fresh install and an in-place upgrade converge
 * on the same state.
 *
 * Generic and opt-in: the caller supplies the phinx configuration (its own `phinx.php`),
 * so the toolkit bakes in no product paths, connection details, or environment names.
 */
final readonly class DatabaseSchemaApplier
{
    /**
     * Apply pending migrations using the phinx config loaded from a PHP config file
     * (typically the product's `phinx.php`).
     *
     * @param string      $phinxConfigPath Absolute path to a phinx PHP config file.
     * @param string|null $environment     Phinx environment; defaults to the config's default.
     *
     * @return string The captured migration output.
     *
     * @throws RuntimeException if the config file is missing or the migration fails.
     */
    public function applyFromPhpConfig(string $phinxConfigPath, ?string $environment = null): string
    {
        if (!is_file($phinxConfigPath)) {
            throw new RuntimeException(sprintf('The phinx config file does not exist: %s', $phinxConfigPath));
        }

        return $this->apply(Config::fromPhp($phinxConfigPath), $environment);
    }

    /**
     * Apply pending migrations for the given phinx configuration.
     *
     * @param string|null $environment Phinx environment; defaults to the config's default.
     *
     * @return string The captured migration output.
     *
     * @throws RuntimeException if the migration fails (the underlying phinx error is chained).
     */
    public function apply(ConfigInterface $config, ?string $environment = null): string
    {
        $output = new BufferedOutput();
        $manager = new Manager($config, new ArrayInput([]), $output);

        try {
            $manager->migrate($environment ?? $config->getDefaultEnvironment());
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Applying database migrations failed: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        return $output->fetch();
    }
}
