# Configuration and Environment Policy

NENE2 uses typed configuration objects as the runtime boundary.

## Position

Configuration should be explicit, typed, and safe to inspect.

The standard direction is:

- Use typed config objects at runtime.
- Keep raw arrays and environment variables at the loading boundary.
- Use `vlucas/phpdotenv` as the first local/test dotenv candidate.
- Prefer real environment variables in production.
- Never commit secrets.

Config loaders, typed config classes, and dotenv loading are implemented under `src/Config/` (see Initial Implementation below).

## Standard Directories

```text
config/                 # non-secret config examples and framework defaults
src/Config/             # typed config objects and loaders
.env.example            # documented non-secret environment shape
.env                    # local secrets, ignored by Git
```

`src/Config/` and `.env.example` are part of the shipped foundation.

## Runtime Boundary

Application code should not call `getenv()`, `$_ENV`, or `$_SERVER` directly outside the config loading layer.

The preferred flow is:

```text
environment variables
        ↓
config loader
        ↓
typed config objects
        ↓
application / infrastructure code
```

This keeps configuration:

- easy to validate
- easy to test
- safe for static analysis
- readable to AI agents

## Loading Order

The intended loading order is:

1. framework defaults from committed non-secret config
2. `.env` values for local and test environments
3. real environment variables
4. explicit test overrides

Later values override earlier values.

Production should rely on real environment variables or platform secrets. Production should not require a committed `.env` file.

## Dotenv Policy

`vlucas/phpdotenv` is the first candidate for local and test `.env` loading because it is common, small, and predictable.

Dotenv loading should happen only during bootstrap. Domain, use-case, and adapter code should receive typed config values rather than reading `.env` directly.

## Initial Implementation

The first implementation uses `vlucas/phpdotenv` for optional local `.env` loading and `src/Config/` for typed config objects.

The initial application config includes:

- `APP_ENV`: `local`, `test`, or `production`
- `APP_DEBUG`: boolean value
- `APP_NAME`: non-empty application name
- database config values used by Phinx and future database adapters:
  `DATABASE_URL`, `DB_ENV`, `DB_ADAPTER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_CHARSET`
- disposable-demo module settings (`Nene2\Demo`, typed as `AppConfig::$demo` / `Nene2\Demo\DemoConfig`; dormant unless the module is wired):
  - `DEMO_MODE`: master switch for the demo start endpoint. Parsed strictly like
    `NENE2_ALLOW_DEV_SECRET` — only `1`/`true`/`yes` enable it; anything else
    (including typos) leaves it off, because the endpoint creates organizations
    without authentication. Default: off.
  - `DEMO_SLUG_PREFIX`: slug namespace separating disposable demo orgs from real
    ones (sweeper selection and capacity count key off it). Default: `demo-`.
  - `DEMO_TTL_HOURS`: hours a demo org lives before the sweeper expires it. Default: `3`.
  - `DEMO_MAX_ORGS`: instance-wide ceiling on concurrently existing demo orgs,
    enforced both at creation time (capacity guard) and by the sweeper. Default: `200`.
  - `DEMO_SLUG_ATTEMPTS`: random slug candidates tried before a conflict is fatal. Default: `5`.

`.env.example` documents safe local defaults. Raw environment access stays inside `ConfigLoader`; application and infrastructure code should receive `AppConfig` and focused nested config objects such as `DatabaseConfig`.

## Secret Policy

Never commit:

- `.env`
- `.env.local`
- `.env.*.local`
- passwords
- tokens
- private URLs
- production credentials

Commit only non-secret examples such as `.env.example`.

## Typed Config Policy

Typed config objects should:

- be immutable when practical
- validate required values at construction time
- expose narrow values, not raw config arrays
- avoid mixing unrelated config groups

Examples of future config groups:

- app/runtime
- HTTP
- database
- logging
- OpenAPI
- frontend assets

## Testing

Tests should use explicit config objects or test overrides. Avoid making tests depend on the developer's local `.env` unless the test is specifically about config loading.

## Future Implementation Issues

Follow-up work should decide:

- dotenv package adoption
- config loader interface
- typed config object structure
- `.env.example` contents
- config validation error shape
- test helper for config construction
