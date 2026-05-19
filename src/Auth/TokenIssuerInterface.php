<?php

declare(strict_types=1);

namespace Nene2\Auth;

/**
 * Issues a signed bearer token string from the given claims.
 *
 * Implementations are responsible for encoding and signing the token.
 * The local development implementation ({@see LocalBearerTokenVerifier}) uses HMAC-HS256.
 * Production deployments should inject a library-backed implementation
 * (e.g. wrapping firebase/php-jwt or lcobucci/jwt).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface TokenIssuerInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims): string;
}
