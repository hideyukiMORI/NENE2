<?php

declare(strict_types=1);

namespace Nene2\Auth;

use RuntimeException;

/**
 * Thrown by {@see GuardedJwtSecretResolver} when a JWT signing/verification secret
 * cannot be resolved safely, so a misconfigured deployment fails closed rather than
 * signing tokens with a public, guessable development secret.
 *
 * The message names the environment variable to set, a generation command, and — in
 * non-production environments only — the opt-in that permits an injected development
 * secret.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class JwtSecretException extends RuntimeException
{
}
