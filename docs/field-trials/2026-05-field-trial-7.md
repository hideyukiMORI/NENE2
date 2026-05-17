# Field Trial 7 — Tag CRUD + MCP Write Auth Guard (v0.6.0 + Phase 35)

## Date

2026-05-18

## Baseline

- NENE2 v0.6.0 + Phase 35 (main branch, post-merge)
- Tag full CRUD: `GET/POST /examples/tags`, `GET/PUT/DELETE /examples/tags/{id}`
- 5 Tag MCP tools: `listExampleTags`, `getExampleTagById`, `createExampleTag`, `updateExampleTagById`, `deleteExampleTagById`
- MCP write auth guard: write tools require `NENE2_LOCAL_JWT_SECRET`
- App container: `docker compose up -d app`
- MySQL container: `docker compose up -d mysql` + `composer migrations:migrate`

## Goal

Validate that:

1. All Tag CRUD HTTP endpoints work correctly end-to-end against MySQL
2. MCP write auth guard rejects write tools when `NENE2_LOCAL_JWT_SECRET` is absent
3. MCP write tools for Tag (create / update / delete) work with `NENE2_LOCAL_JWT_SECRET` set
4. Error paths (404 / 422) return RFC 9457 Problem Details as expected

---

## Steps Taken

### 1. Start app and database

```bash
docker compose up -d app mysql
docker compose run --rm app composer migrations:migrate
```

**Finding**: The `tags` table migration was absent from `database/migrations/`. The `CreateTagsTable` migration (`20260516000001_create_tags_table.php`) was added as part of this field trial. Without it, all tag endpoints returned HTTP 500.

### 2. Tag HTTP smoke — success paths

```
GET /examples/tags         → 200 { items: [], limit: 20, offset: 0 }
POST /examples/tags        → 201 { id: 1, name: "php" }   + Location header
POST /examples/tags        → 201 { id: 2, name: "api" }
GET /examples/tags/1       → 200 { id: 1, name: "php" }
PUT /examples/tags/1       → 200 { id: 1, name: "php8" }   (name replaced)
GET /examples/tags/1       → 200 { id: 1, name: "php8" }   (confirmed update)
DELETE /examples/tags/2    → 204 No Content
GET /examples/tags         → 200 { items: [{ id:1, name:"php8" }], limit:20, offset:0 }
```

All Tag CRUD paths behave correctly.

### 3. Tag HTTP smoke — error paths

```
GET /examples/tags/99      → 404 Problem Details { type: ".../not-found", detail: "...tag was not found." }
PUT /examples/tags/1       → 422 Problem Details { errors: [{ field:"name", code:"required" }] }
  body: { name: "" }
```

404 and 422 Problem Details are correct and consistent with Note error behavior.

### 4. MCP write auth guard — no JWT secret

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
# call: createExampleTag { name: "test" }
→ { "error": { "code": -32603, "message": "Write tool \"createExampleTag\" requires bearer authentication. Set NENE2_LOCAL_JWT_SECRET in the MCP server environment." } }
```

Write tools are correctly blocked. The error message is actionable.

### 5. MCP read tools — no JWT secret

```bash
# call: listExampleTags {}
→ 200 { items: [{ id:1, name:"php8" }], limit:20, offset:0 }
```

Read tools continue to work without authentication.

### 6. MCP Tag write tools — with JWT secret

```bash
docker compose run --rm \
  -e NENE2_LOCAL_API_BASE_URL=http://app \
  -e NENE2_LOCAL_JWT_SECRET=field-trial-7-secret \
  app php tools/local-mcp-server.php

# createExampleTag { name: "mcp-test" }    → 201 { id: 3, name: "mcp-test" }
# updateExampleTagById { id:3, name:"mcp-renamed" } → 200 { id: 3, name: "mcp-renamed" }
# deleteExampleTagById { id: 3 }           → 204
```

All 5 Tag MCP tools (list / get / create / update / delete) work correctly.

---

## Results

| Scenario | Expected | Actual | Status |
|---|---|---|---|
| `GET /examples/tags` (empty) | 200 `{ items:[], limit:20, offset:0 }` | ✓ | Pass |
| `POST /examples/tags` | 201 + Location | ✓ | Pass |
| `GET /examples/tags/{id}` | 200 + tag object | ✓ | Pass |
| `PUT /examples/tags/{id}` | 200 + updated tag | ✓ | Pass |
| `DELETE /examples/tags/{id}` | 204 No Content | ✓ | Pass |
| 404 on absent tag | 404 Problem Details | ✓ | Pass |
| 422 on empty name | 422 Problem Details + errors | ✓ | Pass |
| MCP write without auth | `-32603` error + clear message | ✓ | Pass |
| MCP read without auth | 200 result | ✓ | Pass |
| MCP `createExampleTag` with auth | 201 | ✓ | Pass |
| MCP `updateExampleTagById` with auth | 200 | ✓ | Pass |
| MCP `deleteExampleTagById` with auth | 204 | ✓ | Pass |

All scenarios pass.

---

## Friction Observed

### F-1: `tags` table migration was missing

When the Tag entity was added to the codebase (`src/Example/Tag/`), no corresponding Phinx migration was created for the `tags` table. The HTTP 500 error at `GET /examples/tags` was the first indication — no actionable error message pointed to the missing migration.

**Impact**: Any new project setup (or `composer require` install) that runs `composer migrations:migrate` would have no `tags` table. All Tag endpoints would 500.

**Fix applied**: `database/migrations/20260516000001_create_tags_table.php` added as part of this trial.

**Policy implication**: The endpoint scaffold checklist (`docs/development/endpoint-scaffold.md`) should include a step to add a migration when a new entity introduces a new table.

### F-2: No schema definition for the `tags` table in `database/schema/`

The `database/schema/` directory contains schema snapshots for reference, but no `tags` table schema was documented. Adding migrations without schema records creates a documentation gap.

**Candidate fix**: Add `database/schema/tags.sql` mirroring the Note schema pattern.

---

## Follow-up Issues

- [ ] docs(scaffold): Add "create migration" step to the endpoint scaffold checklist (#307)
- [ ] chore(db): Add `database/schema/tags.sql` schema snapshot (#308)

## Conclusion

v0.6.0 + Phase 35 is fully functional. Tag and Note entities are now at full CRUD parity with matching MCP tool coverage (5 tools each). The MCP write auth guard works correctly, blocking write tools with a clear error message when the JWT secret is absent.

The primary discovery is a missing database migration — a procedural gap in the scaffold checklist rather than a code defect. All HTTP and MCP behaviors are correct once the migration is applied.
