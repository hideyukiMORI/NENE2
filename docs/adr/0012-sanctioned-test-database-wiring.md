# ADR 0012: Sanctioned Test Database Wiring via `Nene2\Testing`

## Status

accepted

## Context

ADR 0009 placed the concrete PDO adapters (`PdoConnectionFactory`,
`PdoDatabaseQueryExecutor`, `PdoDatabaseTransactionManager`) outside the v1.0
public API stability guarantee. The shipped `@internal` annotations make this
boundary explicit at the IDE level.

This boundary is correct for production code: applications should depend on
`DatabaseConnectionFactoryInterface`, `DatabaseQueryExecutorInterface`, and
`DatabaseTransactionManagerInterface`, not on the PDO implementations.

DX Trial 01〜26 (78 trials across new-grad / low-skill / senior personas)
revealed that **test code cannot follow the same rule** without breaking:

- Test fixtures need a concrete `DatabaseQueryExecutorInterface` backed by a real
  database. Producing one requires constructing `PdoConnectionFactory` +
  `PdoDatabaseQueryExecutor` (and `PdoDatabaseTransactionManager` when
  `transactional()` is exercised).
- `PdoDatabaseQueryExecutor`'s second constructor parameter (`?PDO $connection`)
  is the only way to inject a pre-built PDO for tests that need it, but the
  whole class is `@internal`. Trials 13-C and 15-A worked around this with
  anonymous-class subclasses, which is fragile.
- Trial 17-B re-confirmed IMP-18: `:memory:` SQLite is **incompatible with
  `transactional()`** because the transaction manager calls
  `$connectionFactory->create()`, which opens a fresh connection to a different
  empty in-memory database. The trap is silent (the test passes locally if it
  doesn't touch transactions).

## Decision

Introduce a new public namespace `Nene2\Testing\` whose first member is
`DatabaseTestKit`.

### `DatabaseTestKit` shape

```php
namespace Nene2\Testing;

final readonly class DatabaseTestKit
{
    public function __construct(
        public DatabaseConnectionFactoryInterface  $connectionFactory,
        public DatabaseQueryExecutorInterface      $queryExecutor,
        public DatabaseTransactionManagerInterface $transactionManager,
    ) {}

    public static function sqlite(string $path): self;
    public static function fromConfig(DatabaseConfig $config): self;
}
```

- The three interfaces are exposed as **public readonly constructor properties**
  so callers do not need to remember accessor names: `$kit->queryExecutor`,
  `$kit->transactionManager`.
- The factory methods wire `PdoConnectionFactory`, `PdoDatabaseQueryExecutor`,
  and `PdoDatabaseTransactionManager` internally. Callers never name those
  classes.
- `sqlite(':memory:')` throws `InvalidArgumentException`. This converts the
  IMP-18 silent trap into a fail-fast error at the boundary where the test
  fixture is built.
- `fromConfig()` is the escape hatch for MySQL/PostgreSQL fixtures.

### Stability guarantee

`Nene2\Testing\DatabaseTestKit` is added to the v1.0 stable public API surface
(ADR 0009 §3 table).

The PDO concrete adapters retain their `@internal` status. The kit is the
sanctioned bridge; the adapters remain implementation detail.

## Consequences

**Benefits**

- Test code no longer needs to reference `@internal` classes by name.
- The `:memory:` + `transactional()` trap (IMP-18) is now structurally blocked.
- The `DatabaseConfig::sqlite()` factory (IMP-04) and `DatabaseTestKit::sqlite()`
  compose into a one-line test fixture.
- Future test helpers (e.g. for fixture seeding, snapshot reset) have a clear
  home under `Nene2\Testing\`.

**Costs / follow-up work**

- Existing howto pages that show direct `PdoConnectionFactory` / `PdoDatabaseQueryExecutor`
  construction in test snippets should be migrated to `DatabaseTestKit` over
  time (non-breaking; the old pattern still works).
- Each new addition to `Nene2\Testing\` is a public surface and requires either
  this ADR to be extended or a follow-up ADR.

**Out of scope**

- Snapshot / fixture-loading helpers — deferred until a concrete need surfaces.
- Test database lifecycle (create temp file, drop after test) — callers manage
  this with `sys_get_temp_dir()` and `tearDown()`. A helper may be added later.

## Related

- Issue: `#1307`
- Supersedes: none
- Related ADR: `0009-v1.0-public-api-scope.md` (`Nene2\Testing` row added in §3)
- Related work: `docs/todo/dx-trial-improvements.md` IMP-14 / IMP-18 / IMP-04
- Depends on: `DatabaseConfig::sqlite()` factory (#1303 / #1304)
