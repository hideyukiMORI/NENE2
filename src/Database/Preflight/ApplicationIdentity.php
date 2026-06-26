<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

use InvalidArgumentException;

/**
 * The stable identity an application stamps into its own database and expects to find again when it
 * preflights a candidate.
 *
 * The {@see $applicationId} answers "is this database mine?" (vs another application that merely uses
 * the same framework and ledger). The optional {@see $tenantId} answers "is this the database for the
 * tenant I am configured to serve?" — leave it null for single-tenant applications.
 *
 * Written by {@see ApplicationIdentityMarker} and compared read-only by
 * {@see DefaultDatabaseCandidateInspector}. Part of the public API stability guarantee (ADR 0009).
 */
final readonly class ApplicationIdentity
{
    /**
     * @param string $applicationId Stable identifier of this application's database lineage. Must be non-empty.
     * @param string|null $tenantId Tenant/owner identity the application is configured to serve.
     *        Null for single-tenant applications (tenant matching is then `not_applicable`).
     */
    public function __construct(
        public string $applicationId,
        public ?string $tenantId = null,
    ) {
        if ($this->applicationId === '') {
            throw new InvalidArgumentException('Application id must not be empty.');
        }

        if ($this->tenantId === '') {
            throw new InvalidArgumentException('Tenant id must be null or a non-empty string.');
        }
    }
}
