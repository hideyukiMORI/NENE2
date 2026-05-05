<?php

declare(strict_types=1);

namespace Nene2\Database;

use PDO;

interface DatabaseConnectionFactoryInterface
{
    public function create(): PDO;
}
