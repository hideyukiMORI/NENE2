# Field Trial 30 — Project + Task Nested Resource API (projtrack)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/projtrack/`
**NENE2 version**: 1.5.13
**Theme**: Nested resource routes (`/projects/{projectId}/tasks/{taskId}`), multiple path params, cross-entity validation, SQLite foreign key cascade

## What was built

A project and task tracking API with fully nested task routes:

- `GET /projects` — list projects with pagination
- `POST /projects` — create project (name required, description optional)
- `GET /projects/{id}` — show project
- `DELETE /projects/{id}` — delete project (cascade deletes tasks)
- `GET /projects/{projectId}/tasks` — list tasks for a project; `?status=` filter, pagination
- `POST /projects/{projectId}/tasks` — create task in a project
- `GET /projects/{projectId}/tasks/{taskId}` — show task (validates project ownership)
- `PATCH /projects/{projectId}/tasks/{taskId}` — update task title/status/priority
- `DELETE /projects/{projectId}/tasks/{taskId}` — delete task

Task status: `open` → `in_progress` → `done`. Tasks ordered by `priority DESC, created_at ASC`.

31/31 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. `PdoConnectionFactory` does not enable `PRAGMA foreign_keys = ON` for SQLite (High)

**Description**: SQLite does not enforce foreign key constraints (including `ON DELETE CASCADE`) by default. The NENE2 `PdoConnectionFactory` did not set `PRAGMA foreign_keys = ON` after creating the connection. As a result, schemas with `REFERENCES ... ON DELETE CASCADE` silently fail to cascade: child rows are not deleted when a parent row is deleted.

**Severity**: High — data integrity issue that silently corrupts state. Developers define cascade in the schema and naturally expect it to work.

**Reproduction**:
```sql
-- schema.sql
CREATE TABLE tasks (
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    ...
);
```
```php
// Without PRAGMA foreign_keys = ON, this leaves orphaned tasks:
$executor->execute('DELETE FROM projects WHERE id = ?', [$id]);
```

**Fix applied**: `PdoConnectionFactory::create()` now runs `PRAGMA foreign_keys = ON` immediately after creating a SQLite connection.

---

### 2. Multiple path params in nested routes work without friction (Positive)

**Description**: Routes with two path params (e.g., `/projects/{projectId}/tasks/{taskId}`) are registered and dispatch correctly. Both params appear in `$request->getAttribute(Router::PARAMETERS_ATTRIBUTE)` as expected.

**Pattern used**:
```php
$router->get('/projects/{projectId}/tasks/{taskId}', $this->showTask(...));

// In handler:
$params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$projectId = (int) ($params['projectId'] ?? 0);
$taskId    = (int) ($params['taskId'] ?? 0);
```

No friction — the router handles multiple params correctly.

---

### 3. Cross-entity ownership validation pattern — no helper (Low)

**Description**: For nested resources, handlers must validate both that the parent exists (404 if project not found) and that the child belongs to the parent (404 if task not found in that project). This is manual but clear:

```php
private function showTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId); // throws ProjectNotFoundException → 404
    $task = $this->tasks->findByProjectAndId($projectId, $taskId); // throws TaskNotFoundException → 404
    ...
}
```

The two-step pattern is explicit and sufficient. No framework helper needed, but a howto section would help.

---

### 4. No convenient `insert()` usage in v1.5.12 FT — now resolved (Resolved in v1.5.13)

`DatabaseQueryExecutorInterface::insert()` added in v1.5.13 — used throughout this FT project without friction.

## Tests

31 tests, 54 assertions — all pass.

Coverage: list projects (empty, pagination), create project (201, missing name 422, empty name 422, default description), show project (200, 404), delete project (204, 404), cascade (delete project deletes tasks via `PRAGMA foreign_keys = ON`), list tasks (empty, unknown project 404, filter by status, invalid status 422, only project's tasks, pagination), create task (201, unknown project 404, missing title 422, default priority 0), show task (200, 404, cross-project 404), update task (title, status, invalid status 422, not found 404), delete task (204), sort by priority, infrastructure (security headers, X-Request-Id, unknown route 404).
