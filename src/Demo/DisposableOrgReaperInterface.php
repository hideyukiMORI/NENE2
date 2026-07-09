<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * Destroys one disposable demo organization and everything it owns.
 *
 * The product implements this — deliberately. A generic framework reaper cannot
 * know the product's schema, and the common "delete organization" use case of the
 * consuming products does NOT cascade to child tables (verified on invoice:
 * `PdoOrganizationRepository` deletes the org row only). A reaper implementation
 * must therefore delete, in order:
 *
 * 1. child rows (tables carrying `organization_id`, plus grandchildren reachable
 *    only through a parent, e.g. invoice's `line_items`);
 * 2. the organization row itself;
 * 3. product-specific residue outside the database (e.g. invoice's
 *    `var/recurring-runs/org-{id}.txt` run stamps).
 *
 * Contract: `reap()` MUST be idempotent — an organization that is already gone
 * (swept by a concurrent run) is success, not an error. {@see DisposableDemoSweeper}
 * relies on this and does not catch product exceptions.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DisposableOrgReaperInterface
{
    public function reap(int $orgId): void;
}
