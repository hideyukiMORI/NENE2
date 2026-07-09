<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Hands the freshly provisioned demo org's admin session to the browser and
 * redirects into the tenant.
 *
 * This is where the products' authentication differences are fully isolated:
 * invoice issues slug-scoped refresh/CSRF cookies and 302s to the tenant SPA;
 * a JWT-bearer product lands the SPA some other way. Whatever the mechanism,
 * the returned response is served to the user as-is (typically a 302 with
 * `Cache-Control: no-store`).
 *
 * Product-specific session semantics (e.g. invoice's one-shot cookie, where a
 * reload drops to the login screen) stay inside the implementation — they are
 * not part of this contract and must not leak into callers.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface;
}
