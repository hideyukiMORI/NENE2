<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

/**
 * The actionable recommendation a {@see PreflightVerdict} carries for the caller.
 *
 * This is a diagnosis, not an authorization: the application reports what it found about the
 * candidate; the caller (operator, deployment tool, CI) decides what to do. Part of the public
 * API stability guarantee (ADR 0009).
 */
enum PreflightRecommendation: string
{
    /** The candidate is compatible and safe to point the application at as-is. */
    case Safe = 'safe';

    /** The candidate is recognized but requires migration before use (fresh or partial). */
    case NeedsMigration = 'needs_migration';

    /**
     * The candidate cannot be auto-classified as safe and requires human review — e.g. a
     * multi-tenant application whose tenant identity could not be evaluated in this scope.
     */
    case NeedsReview = 'needs_review';

    /** The candidate must not be used (unreachable, foreign schema, or ahead of the application). */
    case Refuse = 'refuse';
}
