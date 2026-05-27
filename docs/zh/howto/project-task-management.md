# 操作指南：项目与任务管理（嵌套资源）

> **FT 参考**：FT241（`NENE2-FT/projtrack`）——项目与任务管理 API

演示两级嵌套资源 API，任务归属于项目。包含父资源存在性校验、通过 `array_key_exists()` 实现的选择性 PATCH 更新、通过 CHECK 约束实现的状态白名单、优先级整数字段，以及 DELETE 响应返回 `204 No Content`。

---

## 路由

| 方法 | 路径 | 说明 |
|----------|---------------------------------------|------------------------------------------------------|
| `GET`    | `/projects`                           | 列出项目（分页） |
| `POST`   | `/projects`                           | 创建项目 |
| `GET`    | `/projects/{id}`                      | 获取单个项目 |
| `DELETE` | `/projects/{id}`                      | 删除项目（级联删除任务） |
| `GET`    | `/projects/{projectId}/tasks`         | 列出项目的任务（分页，可过滤） |
| `POST`   | `/projects/{projectId}/tasks`         | 在项目中创建任务 |
| `GET`    | `/projects/{projectId}/tasks/{taskId}`| 获取单个任务 |
| `PATCH`  | `/projects/{projectId}/tasks/{taskId}`| 选择性更新任务（省略字段保持不变） |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}`| 删除任务（返回 `204 No Content`） |

---

## 数据库结构

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

`status` 在 DB 层通过 `CHECK(status IN (...))` 约束——防止无效值漏入的安全网。`ON DELETE CASCADE` 意味着删除项目会自动删除其所有任务。`priority` 默认为 `0`；值越大排序越靠前。

---

## 嵌套资源：父资源存在性校验

每个任务操作都会在操作任务**之前**校验父项目是否存在。如果项目 ID 未知，会立即抛出 `ProjectNotFoundException`：

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // 确保项目存在（抛出 ProjectNotFoundException → 404）
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` 注册为异常处理器，映射到 `404 Not Found`。这意味着当项目 99 不存在时，`/projects/99/tasks` 返回 `404`——与任务不存在时的状态相同。调用方无法从状态码区分"项目不存在"和"任务不存在"，需读取 Problem Details 的 `detail` 字段。

任务仓储层也在 SQL 层强制执行项目作用域：

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

`WHERE id = ? AND project_id = ?` 防止跨项目访问——项目 1 下的任务 5 无法通过 `/projects/2/tasks/5` 获取，即使任务 5 确实存在。

---

## PATCH：使用 `array_key_exists()` 的选择性字段更新

`PATCH /projects/{projectId}/tasks/{taskId}` 接受 `title`、`status` 和 `priority` 的任意子集。请求体中不包含的字段将被保留。

`isset()` 无法区分"键不存在"和"键存在但值为 null"。对于 PATCH 语义，`array_key_exists()` 是正确的工具：

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

当键不存在时，`$title`、`$status` 和 `$priority` 保持为 `null`。仓储层将 `null` 解释为"不修改"：

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

null 合并运算符 `??` 将提供的值与现有记录合并。始终执行单次 `UPDATE`（无需动态列列表）——字段不存在时，现有值简单地替代自身。

---

## priority 使用 `is_int()`：拒绝 JSON 中的浮点数和字符串

JSON 中的 `1` 解码为 PHP `int`，但 `1.0` 解码为 `float`，`"1"` 解码为 `string`。`is_int()` 只接受整数形式：

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` 会通过 `"1"` 和 `1.0`——对于仅限整数的严格校验，使用 `is_int()`。注意：`priority` 在创建时可选（默认为 `0`）；在 PATCH 时，同样的检查适用于 `array_key_exists('priority', $body)` 块内部。

---

## 状态白名单校验

状态在到达 DB 之前会针对显式白名单进行校验：

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` 严格比较确保值是等于某个允许状态的字符串。DB `CHECK` 约束提供第二层防御，但应用层检查会返回带有有意义错误信息的结构化 `422`，而非原始 DB 错误。

---

## 任务列表的状态过滤

`GET /projects/{projectId}/tasks?status=open` 按状态过滤任务。查询字符串通过 `QueryStringParser::string()` 读取：

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` 在参数不存在时返回 `null`——不应用过滤。无效值返回 `422 Unprocessable Entity`，而非静默返回空列表。

仓储层动态构建 WHERE 子句：

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

任务按 `priority DESC`（优先级高的在前）排序，然后按 `created_at ASC`（同优先级中，旧任务在前）排序。

---

## DELETE 返回 `204 No Content`

DELETE 响应不携带请求体。`JsonResponseFactory::createEmpty(204)` 生成 `204 No Content` 响应：

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

任务仓储层在删除前校验存在性：

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // 不存在则抛出 TaskNotFoundException
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

如果任务不存在（或归属于不同项目），会抛出 `TaskNotFoundException` → 在任何 DELETE 执行之前返回 `404 Not Found`。

---

## 使用的 NENE2 内置功能

| 内置功能 | 用途 |
|---|---|
| `PaginationQueryParser::parse()` | 读取 `?limit=` 和 `?offset=`，带安全默认值 |
| `PaginationResponse` | 生成 `{ items, total, limit, offset }` 信封 |
| `ValidationException` / `ValidationError` | 带 `errors` 数组的结构化 `422` |
| `QueryStringParser::string()` | 读取命名查询字符串参数，不存在时返回 `null` |
| `JsonRequestBodyParser::parse()` | 解码 JSON 请求体 |
| `JsonResponseFactory::create()` | 编码 JSON 响应 |
| `JsonResponseFactory::createEmpty()` | 生成无请求体的响应（例如 `204`） |
| `Router::PARAMETERS_ATTRIBUTE` | 从请求中获取路径参数 |

---

## 相关指南

- [`note-management-ownership.md`](note-management-ownership.md) — 通过 `WHERE id = ? AND owner_id = ?` 防止 IDOR
- [`contact-management.md`](contact-management.md) — 多对多关联、搜索过滤
- [`document-versioning.md`](document-versioning.md) — 带 `is_current` 标志的仅追加版本控制
- [`scheduled-reminders.md`](scheduled-reminders.md) — `V::userId()` 请求头校验模式
