# Architecture Decision Records

Architecture decisions are recorded here as lightweight ADRs. Each record captures the context, decision, and consequences at the time it was made.

## Index

| # | Title |
|---|---|
| [0001](0001-select-http-runtime-packages.md) | Select HTTP runtime packages |
| [0002](0002-use-minimal-psr11-container.md) | Use minimal PSR-11 container |
| [0003](0003-use-phpdotenv-for-local-config-loading.md) | Use phpdotenv for local config loading |
| [0004](0004-use-phinx-migration-runner.md) | Use Phinx migration runner |
| [0005](0005-use-monolog-for-structured-logging.md) | Use Monolog for structured logging |
| [0006](0006-v0.2.0-scope-and-packagist-policy.md) | v0.2.0 scope and Packagist policy |
| [0007](0007-put-vs-patch-policy.md) | PUT vs PATCH policy |
| [0008](0008-jwt-authentication.md) | JWT authentication direction |
| [0009](0009-v1.0-public-api-scope.md) | v1.0 public API scope and stability guarantee |
| [0010](0010-rate-limiting.md) | Rate limiting design |
| [0011](0011-security-review-policy.md) | Security review policy — adversarial review and HTTP security invariants |
| [0012](0012-sanctioned-test-database-wiring.md) | Sanctioned test database wiring via `Nene2\Testing` |
| [0013](0013-guarded-jwt-secret-resolution.md) | Guarded JWT secret resolution — fail-closed framework default |
| [0014](0014-audit-log-framework-module.md) | Audit log framework module (`Nene2\Audit`) |
| [0015](0015-csv-export-framework-module.md) | CSV export framework module (`Nene2\Export`) |

## Template

New ADRs should follow the [template](0000-template.md).
