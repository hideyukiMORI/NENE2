# How-To: Document Template Engine

Demonstrates template CRUD with `{{variable}}` substitution and admin-gated writes.
Field trial: FT197 (`../NENE2-FT/templatelog/`).

## Pattern summary
- `UNIQUE(name)` constraint on templates → 409 on duplicate
- List endpoint excludes `body` to reduce payload
- `POST /templates/{id}/render` accepts `vars` object, substitutes `{{key}}` placeholders
- Unknown variables left as-is (no error)
- Admin key gates create/update/delete; render is public
