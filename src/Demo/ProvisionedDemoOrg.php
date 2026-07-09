<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * The result of provisioning one disposable demo organization.
 *
 * `$adminUserId` is resolved by the provisioner at creation time — the admin user
 * is created in the same unit of work as the organization, so the provisioner is
 * the one place that knows how to identify it. This deliberately removes the
 * product-specific "look the admin up again by role literal" step (invoice used
 * `WHERE role = 'admin'`) from the orchestration.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class ProvisionedDemoOrg
{
    public function __construct(
        public int $orgId,
        public string $slug,
        public int $adminUserId,
    ) {
    }
}
