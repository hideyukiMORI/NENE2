# 操作指南：状态机工作流 API

> **FT 参考**：FT349（`NENE2-FT/workflowlog`）— 带硬编码转换映射的状态机工作流实例、响应中的 `allowed_next`、转换历史日志、终态强制、列表中的状态过滤，13 tests 全部 PASS。

本指南展示如何使用状态机构建工作流引擎：定义允许的状态转换，创建工作流实例，通过状态驱动实例（带操作者归属），并记录完整的转换历史。

## 数据库结构

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- 例如 "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## 工作流定义——"order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled（终态）
        │                ├──► rejected   （终态）
        └──► cancelled   └──► cancelled  （终态）
        （终态）
```

| 当前状态 | 允许的下一步状态 |
|---------|---------------|
| `draft` | `submitted`、`cancelled` |
| `submitted` | `approved`、`cancelled`、`rejected` |
| `approved` | `fulfilled` |
| `fulfilled` | _（终态——无）_ |
| `cancelled` | _（终态——无）_ |
| `rejected` | _（终态——无）_ |

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/workflows/{workflow}/instances` | 创建工作流实例 |
| `GET` | `/workflows/{workflow}/instances` | 列出实例 |
| `GET` | `/workflows/{workflow}/instances/{id}` | 获取实例（含历史） |
| `POST` | `/workflows/{workflow}/instances/{id}/transition` | 驱动状态转换 |

## 创建实例

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← 下一个有效状态
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` 从转换映射计算——始终反映当前状态。

### 未知工作流 → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // 工作流未定义
```

## 列出实例

```php
// "order" 工作流的所有实例
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// 按当前状态过滤
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // 只有草稿状态的实例
```

## 获取实例（含历史）

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` 始终按时间顺序排列（按 `occurred_at` ASC）。列表端点省略 `history` 以提升性能。

## 驱动转换

```php
// 有效转换
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### 完整成功路径

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}    → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}  → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"} → fulfilled

// fulfilled 是终态
→ {"current_state": "fulfilled", "allowed_next": [], ...}
```

### 无效转换 → 409

```php
// draft → approved（必须先经过 submitted）
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transition from 'draft' to 'approved' is not allowed"
}
```

### 终态 → 409

```php
// cancelled 是终态——不允许任何转换
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" 没有允许的转换
```

## 实现

### WorkflowDefinition——转换映射

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // 终态
            'cancelled' => [],     // 终态
            'rejected'  => [],     // 终态
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Unknown workflow: {$workflow}"),
        };
    }
}
```

### 转换处理器

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 无效或终态
    }

    // 原子操作：更新实例 + 插入转换日志
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` 始终从转换映射计算，永远不存储——它与 `current_state` 保持一致。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在 DB 中存储 `allowed_next` | 转换映射变化时数据过时；始终从当前状态计算 |
| 允许自由形式的 `to_state` 不进行白名单检查 | 攻击者可以将状态设置为任意值，绕过工作流逻辑 |
| 跳过转换日志记录 | 没有审计跟踪；无法重建工作流历史或调试卡住的实例 |
| 在 `allowed_next` 中返回终态 | 误导调用者；终态始终有空的 `allowed_next` |
| 对无效转换返回 404 | 404 隐藏"实例未找到"和"转换不允许"的区别；后者使用 409 |
| instances 表中无 `workflow` 字段 | 无法区分不同工作流类型的实例；无法跨工作流查询 |
