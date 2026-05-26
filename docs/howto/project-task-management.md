# How-to: Project and Task Management with Nested Resources

> **FT reference**: FT241 (`NENE2-FT/projtrack`) — Project & Task Management API

Demonstrates a two-level nested resource API where tasks are owned by projects,
with parent existence validation, selective PATCH updates via `array_key_exists()`,
status allowlist via CHECK constraint, priority as an integer, and `204 No Content`
for DELETE responses.

---

## Routes

| Method   | Path                                  | Description                                          |
|----------|---------------------------------------|------------------------------------------------------|
| `GET`    | `/projects`                           | List projects (paginated)                            |
| `POST`   | `/projects`                           | Create a project                                     |
| `GET`    | `/projects/{id}`                      | Get a single project                                 |
| `DELETE` | `/projects/{id}`                      | Delete a project (cascades to tasks)                 |
| `GET`    | `/projects/{projectId}/tasks`         | List tasks for a project (paginated, filterable)     |
| `POST`   | `/projects/{projectId}/tasks`         | Create a task inside a project                       |
| `GET`    | `/projects/{projectId}/tasks/{taskId}`| Get a single task                                    |
| `PATCH`  | `/projects/{projectId}/tasks/{taskId}`| Selectively update a task (omitted fields kept)      |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}`| Delete a task (`204 No Content`)                     |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'done')),
    priority    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`status` is constrained at the DB level with `CHECK(status IN (...))` — a safety net
against invalid values slipping through. `ON DELETE CASCADE` means deleting a project
removes all its tasks automatically. `priority` defaults to `0`; higher values sort first.

---

## Nested resources: parent existence validation

Every task operation validates that the parent project exists **before** touching the
task. If the project ID is unknown, `ProjectNotFoundException` is thrown immediately:

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // Ensure project exists (throws ProjectNotFoundException → 404)
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` is registered as an exception handler that maps to
`404 Not Found`. This means `/projects/99/tasks` returns `404` when project 99 does
not exist — the same status as a missing task. Callers cannot distinguish "project
missing" from "task missing" without reading the Problem Details `detail` field.

The task repository also enforces project scoping at the SQL level:

```php
public function findByProjectAndId(int $projectId, int $taskId): Task
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND project_id = ?',
        [$taskId, $projectId],
    );
    if ($row === null) {
        throw new TaskNotFoundException($projectId, $taskId);
    }
    return $this->hydrate($row);
}
```

`WHERE id = ? AND project_id = ?` prevents cross-project access — task 5 under project
1 cannot be fetched via `/projects/2/tasks/5`, even if task 5 exists.

---

## PATCH: selective field update with `array_key_exists()`

`PATCH /projects/{projectId}/tasks/{taskId}` accepts any subset of `title`, `status`,
and `priority`. Fields absent from the request body are preserved.

`isset()` cannot distinguish `"key absent"` from `"key present with null"`. For PATCH
semantics, `array_key_exists()` is the correct tool:

```php
$title    = null;
$status   = null;
$priority = null;

if (array_key_exists('title', $body)) {
    if (!is_string($body['title']) || trim($body['title']) === '') {
        $errors[] = new ValidationError('title', 'title must be a non-empty string.', 'invalid_value');
    } else {
        $title = trim($body['title']);
    }
}

if (array_key_exists('status', $body)) {
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
        $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
    } else {
        $status = $body['status'];
    }
}

if (array_key_exists('priority', $body)) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`$title`, `$status`, and `$priority` remain `null` when the key is absent. The
repository interprets `null` as "do not change":

```php
public function update(int $projectId, int $taskId, ?string $title, ?string $status, ?int $priority, string $now): Task
{
    $existing    = $this->findByProjectAndId($projectId, $taskId);
    $newTitle    = $title    ?? $existing->title;
    $newStatus   = $status   ?? $existing->status;
    $newPriority = $priority ?? $existing->priority;

    $this->executor->execute(
        'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
        [$newTitle, $newStatus, $newPriority, $now, $taskId, $projectId],
    );

    return $this->findByProjectAndId($projectId, $taskId);
}
```

The null-coalescing `??` merges provided values with the existing record. A single
`UPDATE` always runs (no dynamic column list needed) — the existing value simply
replaces itself when a field is absent.

---

## `is_int()` for priority: rejecting floats and strings from JSON

JSON `1` decodes as PHP `int`, but `1.0` decodes as `float` and `"1"` as `string`.
`is_int()` accepts only the integer form:

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` would pass `"1"` and `1.0` — use `is_int()` for strict integer-only
validation. Note: `priority` is optional on creation (defaults to `0`); on PATCH, the
same check applies inside the `array_key_exists('priority', $body)` block.

---

## Status allowlist validation

Status is validated against an explicit allowlist before it reaches the DB:

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` — strict comparison — ensures the value is a string equal to one
of the allowed states. The DB `CHECK` constraint provides a second layer of defence,
but the application-level check gives a structured `422` with a meaningful error message
rather than a raw DB error.

---

## Status filter for task listing

`GET /projects/{projectId}/tasks?status=open` filters tasks by status. The query string
is read with `QueryStringParser::string()`:

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` returns `null` when the parameter is absent — no filter
applied. An invalid value returns `422 Unprocessable Entity` rather than silently
returning an empty list.

The repository builds the WHERE clause dynamically:

```php
public function findByProject(int $projectId, ?string $status = null, int $limit = 20, int $offset = 0): array
{
    $where  = ['project_id = ?'];
    $params = [$projectId];

    if ($status !== null) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT * FROM tasks WHERE ' . implode(' AND ', $where)
        . ' ORDER BY priority DESC, created_at ASC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    return array_map($this->hydrate(...), $this->executor->fetchAll($sql, $params));
}
```

Tasks are ordered by `priority DESC` (higher priority first), then `created_at ASC`
(older tasks first within the same priority).

---

## `204 No Content` for DELETE

DELETE responses carry no body. `JsonResponseFactory::createEmpty(204)` produces a
`204 No Content` response:

```php
private function deleteTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId);
    $this->tasks->delete($projectId, $taskId);

    return $this->json->createEmpty(204);
}
```

The task repository validates existence before deleting:

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // throws TaskNotFoundException if missing
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

If the task does not exist (or belongs to a different project), `TaskNotFoundException`
is thrown → `404 Not Found` before any DELETE executes.

---

## NENE2 built-ins used

| Built-in | Purpose |
|---|---|
| `PaginationQueryParser::parse()` | Reads `?limit=` and `?offset=` with safe defaults |
| `PaginationResponse` | Produces `{ items, total, limit, offset }` envelope |
| `ValidationException` / `ValidationError` | Structured `422` with `errors` array |
| `QueryStringParser::string()` | Reads a named query string parameter, returns `null` if absent |
| `JsonRequestBodyParser::parse()` | Decodes JSON body |
| `JsonResponseFactory::create()` | Encodes JSON response |
| `JsonResponseFactory::createEmpty()` | Produces body-less response (e.g. `204`) |
| `Router::PARAMETERS_ATTRIBUTE` | Retrieves path parameters from the request |

---

## Related howtos

- [`note-management-ownership.md`](note-management-ownership.md) — IDOR prevention with `WHERE id = ? AND owner_id = ?`
- [`contact-management.md`](contact-management.md) — many-to-many associations, search filtering
- [`document-versioning.md`](document-versioning.md) — append-only versioning with `is_current` flag
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId() header validation pattern
