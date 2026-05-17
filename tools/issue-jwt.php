<?php

declare(strict_types=1);

/**
 * Issue a signed HS256 JWT for local development.
 *
 * Requires NENE2_LOCAL_JWT_SECRET to be set in the environment.
 *
 * Usage:
 *   php tools/issue-jwt.php
 *   php tools/issue-jwt.php --sub user-1 --scope read:system --ttl 3600
 *
 * Options:
 *   --sub    Subject claim (default: local-user)
 *   --scope  Scope claim  (default: read:system)
 *   --ttl    Token TTL in seconds (default: 3600)
 *
 * Output:
 *   The signed JWT on stdout. Redirect or copy as needed.
 *
 * Example:
 *   TOKEN=$(php tools/issue-jwt.php --sub ft6-user)
 *   curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/examples/protected
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Nene2\Auth\LocalBearerTokenVerifier;

$secret = getenv('NENE2_LOCAL_JWT_SECRET');

if (!is_string($secret) || $secret === '') {
    fwrite(STDERR, "Error: NENE2_LOCAL_JWT_SECRET is not set.\n");
    fwrite(STDERR, "Set it in your .env file or export it before running this script.\n");
    exit(1);
}

$opts = getopt('', ['sub:', 'scope:', 'ttl:']);

$sub = is_string($opts['sub'] ?? null) ? $opts['sub'] : 'local-user';
$scope = is_string($opts['scope'] ?? null) ? $opts['scope'] : 'read:system';
$ttl = isset($opts['ttl']) && is_numeric($opts['ttl']) ? (int) $opts['ttl'] : 3600;

$verifier = new LocalBearerTokenVerifier($secret);
$token = $verifier->issue([
    'sub' => $sub,
    'scope' => $scope,
    'iat' => time(),
    'exp' => time() + $ttl,
]);

echo $token . PHP_EOL;
