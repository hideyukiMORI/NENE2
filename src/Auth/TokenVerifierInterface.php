<?php

declare(strict_types=1);

namespace Nene2\Auth;

/**
 * Verifies a raw bearer token string and returns the decoded claims.
 *
 * Throw TokenVerificationException for any failure: expired, bad signature, malformed, etc.
 */
interface TokenVerifierInterface
{
    /**
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     */
    public function verify(string $token): array;
}
