# 带重试和幂等性的后台任务队列

本指南介绍如何在 NENE2 应用程序中实现持久化后台任务队列。该模式支持优先级队列、带退避计数器的自动重试以及幂等性任务创建。

## 核心概念

任务队列将工作与 HTTP 请求周期解耦。HTTP 处理器将任务入队后立即返回；独立的 worker 进程认领并执行任务。

关键状态：`pending` → `running` → `completed` 或 `failed`（有剩余重试次数时自动重新入队）。

## 数据库结构设计

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

`idempotency_key UNIQUE` 在数据库层面强制执行，而不仅仅是应用层面。这防止了两个并发 HTTP 请求都通过应用层检查并都尝试 INSERT 的竞态。

## 任务生命周期

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running（设置 worker_id、claimed_at）
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) 如果还有重试次数
                            → failed 如果 retry_count >= max_retries
```

## 重试逻辑

当 worker 调用 `fail` 时，数据仓库决定是重新入队还是永久失败：

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`error` 字段即使在重新入队时也存储**最近一次**的失败原因，为运维人员在任务记录上提供诊断追踪。

## 幂等性

创建任务时传递 `idempotency_key`，使操作可从 HTTP 客户端安全重试：

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- 首次调用：`201 Created`——任务被创建。
- 使用相同密钥的后续调用：`200 OK`——返回已有任务，不创建副本。

数据库对 `idempotency_key` 的 `UNIQUE` 约束是安全网。先在应用层检查以避免将异常处理作为主要代码路径：

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## 优先级队列

任务按优先级降序认领，同级别内按创建时间升序（FIFO）：

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

优先级级别（存储整数值，暴露人类可读标签）：

| 标签 | 值 |
|------|----|
| low | 0 |
| medium | 10 |
| high | 20 |
| critical | 30 |

## Worker 模式

Workers 是无状态进程，循环执行：认领 → 执行 → 完成或失败。

```
loop:
  job = POST /jobs/claim { worker_id: "worker-1" }
  if job is null → sleep, continue

  try:
    execute(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  catch error:
    POST /jobs/{job.id}/fail { error: error.message }
```

Workers 用 `worker_id` 标识自己，运维人员可以看到哪个 worker 持有某个任务并诊断卡住的 workers。

## 卡住任务检测

`running` 状态且 `claimed_at` 时间戳超过阈值的任务是卡住的（worker 崩溃了）。维护进程应检测并重新入队：

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- 早于超时阈值
  AND retry_count < max_retries
```

## 不可重试任务的 max_retries=0

某些任务绝不能重试（例如支付、重放会造成危害的外部 webhook）。创建时设置 `max_retries: 0`：

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

第一次 `fail` 调用会立即将任务转为 `failed`。

## 设计决策

**为什么将重试逻辑放在数据仓库而不是 worker 中？** 是否重新入队的决定是数据层的不变量（retry_count < max_retries），而非业务逻辑。放在数据仓库中保持 workers 简单，防止不同 workers 实现检查方式不同导致的不一致。

**为什么在 DB 层面对 idempotency_key 添加 UNIQUE 约束？** 应用层检查在并发请求下存在竞态条件。DB 约束是权威性保障；应用层检查是避免依赖异常处理的优化。

**为什么将优先级存储为整数？** 允许以后添加中间优先级别而无需更改模式。人类可读标签是派生的，而非存储的。
