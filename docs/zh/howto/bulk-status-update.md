# 操作指南：批量状态更新 API

> **FT 参考**：FT85（`NENE2-FT/bulkupdatelog`）——批量状态更新 API
> **漏洞评估**：FT231——安全/漏洞评估（V-01 至 V-10）

演示两种批量状态变更模式：逐项更新（每个项目有自己的目标状态）和同构批量更新（所有项目都设置为相同状态）。两者都支持部分成功——响应报告哪些 ID 成功，哪些失败。

---

## 路由

| 方法 | 路径 | 描述 |
|---------|------------------|-----------------------------------------------------|
| `POST` | `/tasks` | 创建任务 |
| `GET` | `/tasks` | 列出所有任务 |
| `PATCH` | `/tasks/status` | 逐项批量状态更新（混合目标状态） |
| `PATCH` | `/tasks/done` | 将一组 ID 标记为已完成（单一目标状态） |

---

## 逐项批量更新（`PATCH /tasks/status`）

每个更新项指定自己的目标状态：

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

仓库逐项处理，累积成功和失败：

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### 响应结构

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

HTTP 状态始终是 `200 OK`——即使所有项目都失败。调用者必须检查 `failed` 来检测逐项错误。

---

## 同构批量更新（`PATCH /tasks/done`）

所有 ID 在单个 `UPDATE ... WHERE id IN (?)` 中移动到相同的目标状态：

```php
// 请求体：{"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

非整数值通过 `array_filter(..., is_int(...))` 静默过滤。过滤后如果结果为空，返回 422。

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // 返回已存在且现在具有目标状态的 ID
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` 生成正确数量的 `?` 占位符——安全，参数化。

---

## 状态白名单（背后枚举）

`TaskStatus` 是一个带四个 case 的背后字符串枚举：

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` 对未知状态值返回 `null`，批量处理器将其映射为逐项失败。数据库结构添加 `CHECK(status IN (...))` 作为数据库层面的保护。

---

## 数据库结构

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## 漏洞评估（FT231）

### V-01 — 任何端点均无认证

**攻击**：不提供凭据批量取消所有任务。

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**观察结果**：`200 OK`——不需要令牌。

**结论**：**EXPOSED**（FT85 演示设计如此）。生产环境添加认证和授权。将批量变更限制为任务所有者或管理员角色。

---

### V-02 — 批量更新 DoS（超大数组）

**攻击**：发送包含数千个项目的 `updates` 数组以耗尽 CPU 或内存。

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**观察结果**：在循环中处理——每个项目运行一个 `UPDATE` 查询。对于 100,000 个项目，这在紧密循环中执行 100,000 条独立的 SQL 语句，没有批次大小限制。

**结论**：**EXPOSED**——添加最大批次大小限制：
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — 通过 `IN` 子句进行 SQL 注入

**攻击**：尝试通过 `IN (?)` 中使用的 `ids` 数组注入 SQL。

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**观察结果**：字符串 `"1; DROP TABLE tasks; --"` 被 `array_filter()` 中的 `is_int()` 过滤器拒绝。只有整数到达 `IN` 子句。`implode` + `array_fill` 模式生成正确数量的 `?` 占位符——没有用户数据的字符串拼接。

**结论**：**BLOCKED**——`is_int()` 过滤器 + 参数化的 `IN` 子句防止注入。

---

### V-04 — 逐项更新中的非整数 ID

**攻击**：在 `updates` 数组中发送非整数 `id` 值。

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**观察结果**：两个项目都被添加到 `$failed`，错误为 `'error' => 'id must be an integer'`。`is_int()` 拒绝字符串和 `null`。

**结论**：**BLOCKED**——每个项目严格的 `is_int()` 类型检查。

---

### V-05 — 无效状态值

**攻击**：在 `updates` 数组中发送未知状态字符串。

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**观察结果**：项目被添加到 `$failed`，错误为 `'error' => 'invalid status value'`。`TaskStatus::tryFrom("hacked")` 返回 `null`。

**结论**：**BLOCKED**——背后枚举 `tryFrom()` 拒绝未知值。

---

### V-06 — 空数组

**攻击**：发送空的 `updates` 或 `ids` 数组。

```json
{"updates": []}
{"ids": []}
```

**观察结果**：两者都返回 `422 Unprocessable Entity`，带有错误消息。

**结论**：**BLOCKED**——处理前检查空数组。

---

### V-07 — 同一批次中的重复 ID

**攻击**：在一个请求中多次包含相同的 `id`。

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**观察结果**：两次更新都成功。第二次 UPDATE 覆盖第一次——任务最终为 `cancelled`。没有去重。

**结论**：**设计接受**——最后写入优先的语义对于简单的任务管理是一致的。如果应该拒绝冲突，处理前对 `ids` 去重并对重复返回错误。

---

### V-08 — 负数和零 ID

**攻击**：发送 ID `0` 或 `-1`。

```json
{"ids": [0, -1]}
```

**观察结果**：`is_int(0)` = true，`is_int(-1)` = true——两者都通过过滤器。UPDATE 以 `WHERE id IN (0, -1)` 运行，不匹配任何行。响应：`{"requested": 2, "updated": 0, "ids": []}`。

**结论**：实际上**BLOCKED**（没有行受影响）。对于不存在的 ID 不返回错误——这与部分成功模式一致。如果负 ID 应该以 422 拒绝，添加正整数保护。

---

### V-09 — 批量更新静默跳过不存在的任务

**攻击**：包含数据库中不存在的 ID。

```json
{"ids": [99999, 100000]}
```

**观察结果**：`{"requested": 2, "updated": 0, "ids": []}` ——没有错误，没有任务不存在的提示。

**结论**：**设计接受**——部分成功模型。在 API 规范中记录此行为。如果调用者需要区分"没有此任务"和"任务已处于目标状态"，响应可以包含 `not_found` 列表。

---

### V-10 — 对相同 ID 的并发批量更新

**攻击**：对相同的 ID 集合同时发送两个 `PATCH /tasks/done` 请求。

**观察结果**：两条 UPDATE 语句在数据库上运行。SQLite 的行级锁意味着一个 UPDATE 先完成，然后第二个 UPDATE 在已经是 `done` 的行上运行。两个响应都返回 `updated` ID（因为行仍然存在，且 `status = done`）。

**结论**：**BLOCKED**——幂等写入。两个请求产生相同的结果（所有 ID 设置为 `done`）。对于目标状态因调用者而异的 `status` 更新，并发写入使用最后写入优先。

---

## 漏洞总结

| # | 攻击向量 | 结论 |
|---|---------------|---------|
| V-01 | 无认证 | EXPOSED（设计如此） |
| V-02 | 批量更新 DoS（超大数组） | EXPOSED |
| V-03 | 通过 `IN` 子句进行 SQL 注入 | BLOCKED |
| V-04 | 非整数 ID | BLOCKED |
| V-05 | 无效状态值 | BLOCKED |
| V-06 | 空数组 | BLOCKED |
| V-07 | 批次中的重复 ID | 设计接受 |
| V-08 | 负数/零 ID | BLOCKED |
| V-09 | 不存在的任务被静默跳过 | 设计接受 |
| V-10 | 并发批量更新 | BLOCKED |

**生产前需要修复的真实漏洞**：
1. **V-01** ——添加认证和授权
2. **V-02** ——添加最大批次大小限制（例如 500 个项目）

---

## 相关操作指南

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) ——带逐项错误的批量创建
- [`batch-api-partial-success.md`](batch-api-partial-success.md) ——部分成功模式
- [`approval-workflow.md`](approval-workflow.md) ——带枚举保护的状态转换
