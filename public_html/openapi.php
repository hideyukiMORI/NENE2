<?php

declare(strict_types=1);

// Block access in production — the OpenAPI spec exposes all routes, schemas, and security requirements.
$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not found.'], JSON_THROW_ON_ERROR);
    exit;
}

$openApiPath = dirname(__DIR__) . '/docs/openapi/openapi.yaml';

if (!is_file($openApiPath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        [
            'error' => 'OpenAPI contract not found.',
        ],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
    );
    exit;
}

header('Content-Type: application/yaml; charset=utf-8');
header('Cache-Control: no-store');

readfile($openApiPath);
