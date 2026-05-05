<?php

declare(strict_types=1);

namespace Nene2\Database;

use Nene2\Config\DatabaseConfig;
use PDO;
use PDOException;

final readonly class PdoConnectionFactory implements DatabaseConnectionFactoryInterface
{
    public function __construct(
        private DatabaseConfig $config,
    ) {
    }

    public function create(): PDO
    {
        try {
            return new PDO(
                $this->dsn(),
                $this->config->user,
                $this->config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException('Database connection could not be created.', previous: $exception);
        }
    }

    private function dsn(): string
    {
        if ($this->config->usesUrl()) {
            return $this->config->url ?? throw new DatabaseConnectionException('DATABASE_URL is missing.');
        }

        return match ($this->config->adapter) {
            'sqlite' => $this->sqliteDsn(),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config->host,
                $this->config->port,
                $this->config->name,
                $this->config->charset,
            ),
            default => throw new DatabaseConnectionException(sprintf(
                'Database adapter "%s" is not supported by the PDO factory.',
                $this->config->adapter,
            )),
        };
    }

    private function sqliteDsn(): string
    {
        if ($this->config->name === ':memory:') {
            return 'sqlite::memory:';
        }

        return 'sqlite:' . $this->config->name;
    }
}
