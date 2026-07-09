<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * Creates one brand-new disposable demo organization plus its admin user.
 *
 * The product implements this as a thin wrapper around its existing
 * "create organization" use case. Contract:
 *
 * - `$slug` is the exact slug to create (already carrying the demo prefix from
 *   {@see DemoConfig::$slugPrefix}); throw {@see SlugConflictException} when it is
 *   taken — the handler retries with a fresh random slug.
 * - `$template` is the validated {@see DemoTemplateKeyInterface::value()} string;
 *   use it for template-dependent creation inputs (e.g. the demo company name).
 * - Generate the admin credentials internally (a random, throwaway password) and
 *   return the created admin's id in {@see ProvisionedDemoOrg::$adminUserId} —
 *   the orchestration never looks the admin up by role literal afterwards.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DisposableOrgProvisionerInterface
{
    /**
     * @throws SlugConflictException when the slug is already taken (retryable).
     */
    public function provision(string $slug, string $template): ProvisionedDemoOrg;
}
