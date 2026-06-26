<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

use Nene2\Database\DatabaseConnectionFactoryInterface;

/**
 * A named candidate database the application may be pointed at, resolved entirely from the
 * application's own configuration.
 *
 * The caller of `POST /machine/database/preflight` supplies only the {@see $id}; the application
 * resolves the matching profile (and its read-only connection factory) from its own environment
 * (e.g. an env allowlist such as `*_DB_CANDIDATE_*`). Connection details and credentials are never
 * accepted from the request body — this prevents the caller from pointing the application at an
 * arbitrary host (SSRF) and keeps credentials off the wire.
 *
 * Part of the public API stability guarantee (ADR 0009).
 */
final readonly class CandidateProfile
{
    /**
     * @param non-empty-string $id Stable identifier the caller references (e.g. `"restore-2026-06"`).
     * @param DatabaseConnectionFactoryInterface $connectionFactory Builds a connection to the candidate
     *        using the application's own credentials. A least-privilege (SELECT-only) credential is
     *        recommended; the inspector additionally enforces a read-only transaction.
     * @param bool $multiTenant Whether the application is deployed in a multi-tenant configuration.
     *        When true, a verdict that would otherwise be `safe` is downgraded to `needs_review`
     *        until tenant identity can be evaluated (see issue #1420). Single-tenant applications
     *        leave this false and are unaffected.
     */
    public function __construct(
        public string $id,
        public DatabaseConnectionFactoryInterface $connectionFactory,
        public bool $multiTenant = false,
    ) {
    }
}
