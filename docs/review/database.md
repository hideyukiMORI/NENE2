# Database Self-Review

Use this checklist for database adapter work, migrations, seeds, schema snapshots, repositories, and persistence boundaries.

Source policies:

- `docs/development/database-migrations.md`
- `docs/development/configuration.md`
- `docs/development/coding-standards.md`

## Checklist

- [ ] Framework core remains database-independent unless an ADR changes that boundary.
- [ ] Migrations are placed under `database/migrations/`.
- [ ] Seeds are placed under `database/seeds/` and do not contain production secrets or private data.
- [ ] Schema snapshots or docs are placed under `database/schema/` when introduced.
- [ ] Database credentials come from typed config or environment boundaries, not hard-coded values.
- [ ] Use cases remain independent from concrete database tools.
- [ ] Repository or adapter boundaries hide persistence details from domain and use-case code.
- [ ] Database tool adoption, such as Phinx, is documented before becoming required.
- [ ] The narrowest useful PHP verification was run, usually `composer check`.
