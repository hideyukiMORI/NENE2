# Machine database preflight

`POST /machine/database/preflight` lets an application inspect a *candidate* database read-only and
answer one question: **"is this candidate database legitimate for me?"** Use it before pointing an
application at an existing database — environment clones, restores/migrations, moving between DB
servers.

The endpoint is auth-gated (`X-NENE2-API-Key`), strictly read-only, and resolves the candidate from
the application's own configuration by id. DSN, host, and credentials are never accepted in the
request body. See the OpenAPI operation `machineDatabasePreflight` for the request/response contract.

## The verdict

The response carries reason codes only — never raw database names, hosts, or row data:

| Field | Meaning |
|---|---|
| `reachable` | A read-only connection could be opened. |
| `schema_recognized` | The candidate carries this framework's migration ledger (`phinx_log`). |
| `migration_state` | `fresh` \| `compatible` \| `ahead` \| `foreign` \| `partial`. |
| `populated` | The candidate holds application tables or migration history. |
| `recommendation` | `safe` \| `needs_migration` \| `needs_review` \| `refuse` (+ `reason_codes`). |
| `app_identity` | `not_evaluated` \| `match` \| `mismatch` \| `absent`. |
| `tenant` | `not_applicable` \| `match` \| `mismatch`. |

`ahead`, `foreign`, an `app_identity: mismatch`, and a `tenant: mismatch` all refuse automatically.

## Application identity

The migration ledger tells you a database belongs to *some* application built on this framework. It
does not tell you the database is **this** application's — another app using the same framework and
the same migrations would look identical. The application identity marker closes that gap.

`ApplicationIdentity` carries a stable `applicationId` (your database lineage) and an optional
`tenantId` (null for single-tenant applications). Stamp it into your own database with
`ApplicationIdentityMarker`:

```php
use Nene2\Database\Preflight\ApplicationIdentity;
use Nene2\Database\Preflight\ApplicationIdentityMarker;

$marker = new ApplicationIdentityMarker($yourDatabaseConnectionFactory);
$marker->stamp(new ApplicationIdentity('orders-prod'));            // single-tenant
$marker->stamp(new ApplicationIdentity('orders-prod', 'acme'));    // multi-tenant
```

`stamp()` is idempotent — it creates the `nene2_app_identity` table if needed and replaces the single
marker row. Run it at initialization / migration time so every database your application owns is
stamped going forward.

Wire your identity into the inspector so the verdict evaluates it:

```php
use Nene2\Database\Preflight\DefaultDatabaseCandidateInspector;

$inspector = new DefaultDatabaseCandidateInspector(
    applicationMigrationVersions: $yourKnownVersions,
    applicationIdentity: new ApplicationIdentity('orders-prod'),
);
```

## Backfilling an existing database

The most important case — restoring or adopting a database that predates the identity marker — would
fail closed if a missing marker were treated as "foreign". It is not. **An absent marker never
refuses**: the verdict reports `app_identity: absent` with the `identity_unverified` reason code and
keeps the migration-based recommendation (so a compatible candidate stays `safe`/`needs_migration`).

To adopt such a database, confirm the verdict is otherwise acceptable, then stamp it once:

```php
// One-off backfill against the existing database you are adopting.
$marker = new ApplicationIdentityMarker($candidateConnectionFactory);
$marker->stamp(new ApplicationIdentity('orders-prod'));
```

After backfilling, subsequent preflights report `app_identity: match`. Only a marker that names a
*different* application produces `mismatch` (and refuses).

## Scope

Identity and tenant matching are the marker comparison only — richer tenant resolution belongs in an
application-specific `DatabaseCandidateInspector`. Applying a database switch, hot-swapping live
connections, and running migrations are out of scope for this endpoint; it is read-only diagnosis.
