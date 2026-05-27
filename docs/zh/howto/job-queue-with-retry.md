# 操作指南：带重试和幂等性的后台任务队列

> **FT 参考**：FT255（`NENE2-FT/queuelog`）——带重试和幂等性的后台任务队列
> **VULN**：FT255——漏洞评估（V-01 至 V-10）

演示基于 SQLite 的持久化任务队列。任务具有优先级，通过 `pending → running → completed|failed` 状态机流转，失败时支持自动重试并可配置重试限制。幂等性密钥防止重复创建任务。包含完整的漏洞评估。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/jobs` | 入队任务（可选幂等性密钥） |
| `GET`  | `/jobs` | 列出任务（可按状态过滤） |
| `GET`  | `/jobs/{id}` | 获取单个任务 |
| `POST` | `/jobs/claim` | Worker 认领下一个待处理任务 |
| `POST` | `/jobs/{id}/complete` | Worker 标记任务完成 |
| `POST` | `/jobs/{id}/fail` | Worker 标记任务失败（含重试） |

> **路由顺序**：`/jobs/claim` 必须注册在 `/jobs/{id}` 之前，以免字面量段 `claim` 被捕获为路径参数。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key TEXT UNIQUE` 在 DB 层面强制唯一性。`claimed_at`、`worker_id` 和 `error` 可为空——仅在任务进入 `running` 或 `failed` 状态时设置。

---

## 优先级：用于 SQL 排序的数值枚举

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

数值允许直接用 `ORDER BY priority DESC` 排序。字符串枚举则需要 `CASE` 表达式或优先级查找表。值之间的间距（0, 10, 20, 30）允许插入未来的优先级别而无需重新编号。

---

## 认领：最高优先级 FIFO

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` 选择优先级最高的任务，同等优先级中选最老的（FIFO）。`LIMIT 1` 确保只选一个任务。

此认领是**非原子的**（见 V-06）。单 worker 场景可接受。并发 workers 场景应使用 SQLite 的 `BEGIN IMMEDIATE` + `SELECT … LIMIT 1 FOR UPDATE`（MySQL）或带 `changes()` 检查的 `status = 'pending' AND id = ?` 条件 UPDATE。

---

## 重试逻辑：重新入队与失败

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // 重新入队：重置为 pending 并增加 retry_count
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // 耗尽：永久失败
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` 检查是否还有重试次数。有则任务返回 `pending`（清除 `claimed_at`/`worker_id`）并可被再次认领。耗尽则转入终态 `failed`。

重新入队时清除 `claimed_at = NULL` 和 `worker_id = NULL`，使任务对下一个认领它的 worker 看起来像全新的待处理任务。

---

## 幂等性密钥：创建时去重

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

如果具有相同 `idempotency_key` 的任务已存在，返回已有任务并以 `200 OK` 响应，而非创建副本。新任务返回 `201 Created`。`idempotency_key` 上的 `UNIQUE` 约束提供针对竞态条件的第二层防护。

---

## 状态机

```
pending ──(claim)──→ running ──(complete)──→ completed（终态）
                        │
                        └──(fail，还有重试次数)──→ pending
                        │
                        └──(fail，重试耗尽)──→ failed（终态）
```

`complete()` 和 `fail()` 都在应用状态转换前检查 `status = Running`。两者返回 `null` 表示任务未找到或状态不正确，由控制器映射为 `409 Conflict`。

---

## VULN——漏洞评估（FT255）

### V-01 — 无认证：任何调用者都能入队、认领或完成任何任务

**风险**：所有端点均未认证。

**影响**：攻击者可以以任意类型和载荷入队任意任务，认领合法任务阻止真实 workers 处理，并在未执行实际工作的情况下标记任务完成或失败。

**结论**：⚠️ EXPOSED——添加认证。Worker 端点（`/jobs/claim`、`/jobs/{id}/complete`、`/jobs/{id}/fail`）应要求 worker API 密钥或 JWT。入队应限于经认证的生产者。

---

### V-02 — 任务类型为任意字符串：不强制白名单

**风险**：`type` 接受任意非空字符串。攻击者可以入队系统不处理的类型的任务（例如 `"DROP TABLE"`、`"shutdown"`、`"admin_task"`）。

**影响**：如果 worker 基于 `type` 分发（例如 `match($job->type) { ... }`），未知类型会被静默跳过或触发意外的默认处理器。

**结论**：⚠️ EXPOSED——根据已知任务类型的白名单校验 `type`。未知类型返回 `422`。示例：

```php
if (!in_array($type, ['email', 'pdf', 'sync'], true)) {
    return $this->problems->create($request, 'validation-failed', '...', 422, ...);
}
```

---

### V-03 — 优先级操控：攻击者设置 `critical` 优先级

**攻击**：以 `"priority": "critical"` 入队任务，抢占所有现有任务。

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**观察结果**：请求以 `201` 成功。垃圾任务现在排在队列最前面，在所有合法高优先级任务之前被认领。

**结论**：⚠️ EXPOSED——限制谁可以设置高优先级。没有提升权限的生产者应限制为 `low` 或 `medium`。对未认证调用者拒绝 `critical`。

---

### V-04 — Worker ID 欺骗：任何人都可以用任意 worker_id 认领

**攻击**：提交带有 `"worker_id": "legitimate-worker-1"` 的认领请求。

**观察结果**：认领成功——任务被分配给欺骗的 worker ID。合法 worker 无法将其与自己的认领区分。

**结论**：⚠️ EXPOSED——`worker_id` 应从经认证的身份（API 密钥 → worker 名称）派生，而非由调用者提供。永远不要信任调用者提供的 worker ID。

---

### V-05 — 任务状态接管：任何调用者都能完成/失败任何运行中的任务

**攻击**：完成或失败一个由不同 worker 认领的任务。

```bash
# Worker A 认领任务 1；攻击者在 Worker A 完成前标记完成：
POST /jobs/1/complete
```

**观察结果**：`complete()` 只检查 `status = Running`。没有所有权检查来验证调用者是否是认领该任务的 worker。

**结论**：⚠️ EXPOSED——在 `complete()` 和 `fail()` 中添加 `WHERE worker_id = $requestWorkerId` 条件。如果 worker 不拥有该任务则返回 `409`。

---

### V-06 — 认领时的竞态条件：非原子 SELECT + UPDATE

**风险**：`claim()` 执行 `SELECT … LIMIT 1` 然后 `UPDATE … WHERE id = ?`。两个并发 workers 可能在任一更新前都选中同一任务。

**攻击**：两个 workers 都看到任务 1 为 `pending`，都将其更新为 `running`，都执行该任务。第二次更新赢得 `worker_id` 列，但任务运行了两次。

**结论**：⚠️ EXPOSED——使用原子认领模式：
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
然后检查 `changes() = 1`。在 SQLite 上，用 `BEGIN IMMEDIATE` 包裹可防止并发读取看到同一个待处理行。

---

### V-07 — 载荷大小：任务载荷无大小限制

**风险**：`payload` 接受任意 JSON 对象，无大小校验。

**影响**：当 workers 获取任务或列出队列时，多兆字节的载荷会消耗存储和内存。

**结论**：⚠️ EXPOSED——添加载荷大小检查（例如 `strlen($json) > 65536 → 422`）。依赖请求大小中间件作为外部限制。

---

### V-08 — 通过 type 或 payload 的 SQL 注入

**攻击**：在 `type` 或 `payload` 字段中嵌入 SQL 元字符。

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**观察结果**：值作为参数化 `?` 占位符绑定。注入以字面文本存储在数据库中；SQL 从未执行。

**结论**：🚫 BLOCKED——参数化查询防止 SQL 注入。

---

### V-09 — 幂等性密钥碰撞：攻击者猜测合法密钥

**攻击**：猜测或枚举合法调用者的幂等性密钥，并提交带有不同载荷的相同任务。

**观察结果**：已有任务原样返回。攻击者的请求不会创建新任务——`UNIQUE` 约束和应用级检查都阻止了这一点。攻击者能通过返回的 `200` 得知任务存在，但无法修改它。

**结论**：⚠️ PARTIALLY BLOCKED——重复创建被阻止。但攻击者可通过探测幂等性密钥枚举任务是否存在。使用长随机密钥（例如 UUID v4）使枚举不可行。匹配密钥的响应会泄露任务存在及其状态。

---

### V-10 — 失败任务中的错误消息泄露

**风险**：`POST /jobs/{id}/fail` 中 worker 的错误消息存储在 `error` 列中，并在所有列表/获取响应中返回。

**影响**：workers 提交的内部错误消息（堆栈跟踪、DB 连接字符串、内部文件路径）对 `GET /jobs` 的任何调用者可见。

**结论**：⚠️ EXPOSED——存储前对错误消息进行脱敏（去除敏感细节）。在列表/获取响应中将 `error` 字段可见性限制为管理员角色。

---

## VULN 汇总

| # | 漏洞 | 结论 |
|---|------|------|
| V-01 | 所有端点无认证 | ⚠️ EXPOSED |
| V-02 | 任务类型：无白名单 | ⚠️ EXPOSED |
| V-03 | 优先级操控（critical 任务） | ⚠️ EXPOSED |
| V-04 | Worker ID 欺骗 | ⚠️ EXPOSED |
| V-05 | 任务状态接管（无所有权检查） | ⚠️ EXPOSED |
| V-06 | 认领时的竞态条件（非原子） | ⚠️ EXPOSED |
| V-07 | 载荷大小：无限制 | ⚠️ EXPOSED |
| V-08 | 通过 type/payload 的 SQL 注入 | 🚫 BLOCKED |
| V-09 | 幂等性密钥碰撞/枚举 | ⚠️ PARTIALLY BLOCKED |
| V-10 | 列表中的错误消息泄露 | ⚠️ EXPOSED |

**生产前必须修复的关键问题**：
1. **V-01** — 为生产者和 workers 添加认证（单独的认证级别）
2. **V-02** — 根据已知白名单校验 `type`
3. **V-03 / V-04 / V-05** — 从经认证的会话派生 worker 身份；添加 `worker_id` 所有权检查
4. **V-06** — 使用原子认领（`UPDATE … WHERE … AND status='pending'` + `changes() = 1`）
5. **V-10** — 存储前对 worker 错误消息进行脱敏；限制可见性

---

## 相关操作指南

- [`notification-queue.md`](notification-queue.md) — 通知队列 API（notiflog FT214）
- [`idempotency.md`](idempotency.md) — POST 请求的幂等性密钥模式
- [`dead-letter-queue.md`](dead-letter-queue.md) — 带重试的死信队列（deadletterlog FT72）
- [`transactions.md`](transactions.md) — 在事务中包裹队列操作
