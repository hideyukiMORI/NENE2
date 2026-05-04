<?php

declare(strict_types=1);

use Nene2\FrameworkInfo;

require dirname(__DIR__) . '/vendor/autoload.php';

$framework = new FrameworkInfo();

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'name' => $framework->name(),
        'description' => $framework->description(),
        'status' => 'ok',
    ],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
);
