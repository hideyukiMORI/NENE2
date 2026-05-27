# 操作指南：定时提醒 API

> **FT 参考**：FT235（`NENE2-FT/reminderlog`）——定时提醒 API

演示提醒调度 API，带有时区感知的未来日期时间校验、通过请求头轻量级的按请求用户标识、通过所有权作用域查询的 IDOR 防护，以及取消提醒时的 404/409 区分。

---

## 路由

| 方法 | 路径 | 说明 |
|---------|-----------------------------|-------------------------------------------------------|
| `POST`  | `/reminders`                | 创建提醒（需要未来的 `remind_at`） |
| `GET`   | `/reminders`                | 列出调用者的提醒（可按状态过滤） |
| `PATCH` | `/reminders/{id}/cancel`    | 取消待处理的提醒 |

所有路由都需要 `X-User-Id` 头。

---

## 通过请求头轻量级用户标识

与 Bearer JWT 不同，此 API 使用 `X-User-Id` 整数头作为最小的认证/标识机制：

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` 校验头值：

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false——空字符串已被拒绝。
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

关键属性：
- `ctype_digit()` — 免疫 ReDoS，拒绝 `0`、`-1`、`1.5`、`abc`、空字符串。
- `strlen > 18` — `(int)` 转换前的溢出守护（`PHP_INT_MAX` 是 19 位数字）。
- `$id > 0` — 拒绝解析后的整数零。

在生产环境中，替换为 JWT 或 session 校验。`X-User-Id` 模式适用于上游网关已认证用户并转发其 ID 的内部服务。

---

## 未来日期时间校验（时区感知）

`remind_at` 必须是带有明确时区偏移的有效 ISO 8601 日期时间，**并且**相对于当前时间必须严格在未来：

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` 组合两个检查：

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // 步骤 1：格式 + 范围校验

    if ($dt === null) {
        return null;
    }

    // 步骤 2：时区感知的未来检查
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // 对象比较规范化为 UTC
}
```

`V::isoDatetime()` 首先执行格式检查：

```php
public static function isoDatetime(mixed $raw): ?string
{
    // 严格正则：要求 ±HH:MM 偏移——拒绝 'Z'、仅日期、缺少偏移。
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // 校验时区偏移范围：有效 UTC 偏移为 −14:00 … +14:00。
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... 溢出日期（如 2 月 30 日等）的往返校验
}
```

`DateTimeImmutable` 对象比较（`>`）在比较前将两侧都转换为 UTC——因此 `2026-06-01T09:00:00+09:00`（UTC 00:00）与 `2026-06-01T01:00:00+01:00`（UTC 00:00）被正确比较为相等。

---

## IDOR 防护：所有权作用域查找

所有涉及特定提醒的操作都使用 `WHERE id = ? AND user_id = ?`：

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

如果提醒属于另一个用户，`findForUser()` 返回 `null`——调用者收到 `404 Not Found`，与"提醒不存在"无法区分。返回 `403 Forbidden` 会确认 ID 存在，泄露枚举信息。

---

## 404 vs 409：先获取后取消

取消处理器在检查状态之前先获取提醒。这种两步方法允许为每种失败模式返回正确的 HTTP 状态：

```php
// 先获取以区分 404（未找到/错误所有者）和 409（错误状态）
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

DB 层取消包含状态守护作为安全保底：

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

UPDATE 中的 `WHERE status = 'pending'` 确保竞态条件（两个并发取消请求）只导致一行被更新。

---

## 查询参数校验（`?limit=` 和 `?status=`）

`limit` 使用 `V::queryInt()`，区分键不存在（使用默认值）和无效值（返回 422）：

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20——键不存在时返回
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` 使用 `V::enum()` 针对支持枚举进行校验：

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` 内部调用 `BackedEnum::tryFrom()`，对未知值返回 `null`。

---

## 数据库结构

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 带时区偏移，原样存储
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` 存储为带提交者时区偏移的原始 ISO 8601 字符串（例如 `2026-06-01T09:00:00+09:00`）。DB 不规范化为 UTC——应用负责正确比较（见 `V::futureDatetime()`）。

两个索引：
- `(user_id, id)` — 覆盖按用户列表和取消查找
- `(status, id)` — 覆盖轮询器查询，获取应触发的 `pending` 提醒

---

## 状态枚举

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

只有 `pending` 提醒可以取消（否则返回 `409`）。`triggered` 由后台任务在提醒触发时设置——此 API 不包含触发端点，触发端点应在 HTTP 服务器之外的定时任务上运行。

---

## 相关指南

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601 日期时间校验模式
- [`content-scheduling.md`](content-scheduling.md) — 带未来 `publish_at` 的定时发布
- [`approval-workflow.md`](approval-workflow.md) — 状态转换中的 404/409 区分
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防护模式
