# 操作指南：死信队列（DLQ）

> **FT 参考**：FT72（`NENE2-FT/deadletterlog`）——死信队列 API

演示带指数退避重试机制的可靠消息队列和死信队列。失败的消息会以递增的延迟自动重新调度；在耗尽所有重试次数后进入 `dead` 状态，可在此状态下进行检查和重放。通过路径参数支持多个具名队列。

---

## 消息生命周期

```
入队 ──▶ pending ──claim──▶ processing
                                │
                    ┌──succeed──┤──fail（有重试次数）──▶ pending（retry_after）
                    │           │
                    ▼           └──fail（已耗尽）──▶ dead ──replay──▶ pending
                succeeded
```

| 状态 | 描述 |
|------|------|
| `pending` | 可被认领（或等待至 `retry_after`） |
| `processing` | 已被 worker 认领，正在处理中 |
| `succeeded` | 已成功完成 |
| `dead` | 耗尽所有重试次数——进入死信队列 |

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/queues/{queue}/messages` | 入队一条消息 |
| `GET` | `/queues/{queue}/messages` | 列出队列中的消息 |
| `GET` | `/queues/{queue}/messages/{id}` | 获取单条消息 |
| `POST` | `/queues/{queue}/claim` | 认领下一条待处理消息 |
| `POST` | `/queues/{queue}/messages/{id}/succeed` | 标记为成功 |
| `POST` | `/queues/{queue}/messages/{id}/fail` | 标记为失败（重试或进入 DLQ） |
| `POST` | `/queues/{queue}/messages/{id}/replay` | 重放死信消息 |

---

## 入队消息

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // 必填字符串
    'max_retries' => 5,  // 可选，默认 3，范围 1–10
];
```

`max_retries` 校验必须在 1 到 10 之间：

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## 认领下一条待处理消息

Worker 调用 `POST /queues/{queue}/claim` 以原子方式出队一条消息：

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // 没有可用消息
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` 过滤掉正在重试等待中的消息。消息按 FIFO 顺序认领（`ORDER BY created_at ASC`）。

> **原子性说明**：没有事务保护时，两个并发 worker 可能在任一 UPDATE 执行前都读取到同一行，从而认领同一条消息。应将 SELECT + UPDATE 包装在带 `SELECT ... FOR UPDATE`（MySQL/PostgreSQL）的事务中，或使用 `UPDATE ... WHERE status = 'pending' RETURNING id` 实现真正的原子认领。

---

## 指数退避失败处理

当 worker 报告失败（`POST .../fail`）时，仓库层要么安排重试，要么将消息升级至死信队列：

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // 已耗尽——移入 DLQ
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // 安排指数退避重试
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### 退避时间表（max_retries = 5）

| 失败次数 | 退避秒数 | 计算公式 |
|---------|---------|---------|
| 第 1 次失败 | 2 秒 | 2^1 |
| 第 2 次失败 | 4 秒 | 2^2 |
| 第 3 次失败 | 8 秒 | 2^3 |
| 第 4 次失败 | 16 秒 | 2^4 |
| 第 5 次失败 | → dead | 重试已耗尽 |

`min(2 ** $newRetryCount, 3600)` 将最大退避时间限制在 1 小时。对于大量重试次数，这避免了多天延迟，同时仍给服务留有恢复时间。

---

## 重放死信消息

死信消息可通过重置为 `pending` 状态并清空重试状态来重放：

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` 重置为 0，使消息重新获得完整的 `max_retries` 预算。原始 `max_retries` 值保持不变。

> **最佳实践**：重放前请先修复失败的根本原因。在系统仍处于故障状态时重放只会重新填满 DLQ。

---

## 多具名队列

`{queue}` 路径参数按名称路由消息。任何非空字符串均有效：

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

所有查询均按 `queue = ?` 过滤，各队列相互隔离。无需注册队列——队列在首次入队时隐式创建。

---

## 数据库结构

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- 未安排重试时为 NULL
    last_error  TEXT,           -- 首次失败前为 NULL
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

关键设计决策：
- `payload` 是不透明字符串——队列不检查或校验消息内容。
- `last_error` 存储最近一次失败的消息，用于调试。
- `retry_after` 对新消息为 `NULL`，重放时清空，使 `retry_after <= now` 无需特殊处理即可正常工作。

---

## Worker 模式

Worker 每次轮询并处理一条消息：

```php
// Worker 循环（伪代码）
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // 无消息，退避等待
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

保持 claim 到 succeed/fail 的处理周期简短。长时间处理而没有超时机制，会在 worker 崩溃时导致消息永久停留在 `processing` 状态。建议添加 `processing_timeout` 列和回收超时消息的定时任务。

---

## 相关操作指南

- [`job-queue.md`](job-queue.md) ——不含 DLQ 的基本任务队列
- [`notification-queue.md`](notification-queue.md) ——通知队列模式
- [`idempotency.md`](idempotency.md) ——至少一次投递的幂等处理
- [`webhook-delivery.md`](webhook-delivery.md) ——Webhook 重试模式
