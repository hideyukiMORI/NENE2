<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * Seeds a freshly provisioned demo organization with the template's business data.
 *
 * The product implements this; the seed content (industries, amounts, dates) is
 * entirely product-specific. Contract:
 *
 * - Write through ONE injected connection — the same connection/executor the
 *   application request is already using. Opening a second PDO to the same
 *   database is forbidden: under SQLite it contends with the app's own
 *   connection and fails with "database is locked" (observed on invoice).
 * - Every row must carry the explicit `$orgId` (never a request-scoped tenant
 *   holder): the demo start request is org-less at entry, so seeding is a
 *   deliberate cross-tenant write into the org that was just created.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DemoDataSeederInterface
{
    public function seed(int $orgId, DemoTemplateKeyInterface $template): void;
}
