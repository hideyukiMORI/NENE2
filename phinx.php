<?php

declare(strict_types=1);

$databaseUrl = getenv('DATABASE_URL');

return [
    'paths' => [
        'migrations' => 'database/migrations',
        'seeds' => 'database/seeds',
    ],
    'environments' => [
        'default_environment' => getenv('DB_ENV') ?: 'local',
        'local' => $databaseUrl !== false && $databaseUrl !== ''
            ? ['url' => $databaseUrl]
            : [
                'adapter' => getenv('DB_ADAPTER') ?: 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'name' => getenv('DB_NAME') ?: 'nene2',
                'user' => getenv('DB_USER') ?: 'nene2',
                'pass' => getenv('DB_PASSWORD') ?: '',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
            ],
    ],
    'version_order' => 'creation',
];
