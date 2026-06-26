<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

/**
 * Inspects a candidate database read-only and returns a structured {@see PreflightVerdict}.
 *
 * This is the extension point behind `POST /machine/database/preflight`. Most applications use the
 * shipped {@see DefaultDatabaseCandidateInspector}; implement this interface to add application
 * specific checks (custom identity markers, domain invariants) while reusing the endpoint and the
 * verdict contract.
 *
 * Implementations MUST NOT issue any DDL or DML against the candidate — this is a diagnosis, not a
 * migration. Part of the public API stability guarantee (ADR 0009).
 */
interface DatabaseCandidateInspector
{
    /**
     * Open a read-only connection to the candidate described by $profile, inspect it, and return a
     * verdict. Connection failures are reported as an unreachable verdict rather than thrown
     * (see {@see PreflightVerdict::unreachable()}).
     */
    public function inspect(CandidateProfile $profile): PreflightVerdict;
}
