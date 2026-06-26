<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

/**
 * Classification of a candidate database's migration state relative to the inspecting application.
 *
 * Computed by {@see DatabaseCandidateInspector} from the candidate's migration ledger and the
 * application's own known migration versions. Part of the public API stability guarantee (ADR 0009).
 */
enum MigrationState: string
{
    /** The candidate is empty — no application tables and no recognized migration ledger. */
    case Fresh = 'fresh';

    /** The candidate's applied migrations exactly match what the application expects. */
    case Compatible = 'compatible';

    /** The candidate has applied migrations the application does not know about (candidate is ahead). */
    case Ahead = 'ahead';

    /** The candidate holds schema/data but no recognized migration ledger — it is not this framework's. */
    case Foreign = 'foreign';

    /** The candidate's ledger is recognized but some expected migrations are missing (interrupted/incomplete). */
    case Partial = 'partial';
}
