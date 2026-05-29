# 操作指南：时间追踪 API

> **FT 参考**：FT246（`NENE2-FT/timelog`）— 时间追踪 API

演示一个秒表式时间追踪 API：计时条目有 `start_time` 和可为空的 `end_time`（`NULL` = 运行中，非 `NULL` = 已停止），同一时间只能运行一个计时器，时长通过 SQLite 的 `strftime('%s', ...)` 计算，每日汇总按日历日聚合追踪的总秒数。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/timers/start` | 启动新计时器（已有运行中的计时器则失败） |
| `POST` | `/timers/stop` | 停止当前运行的计时器 |
| `GET` | `/timers/running` | 获取当前运行的计时器（或 `running: false`） |
| `GET` | `/timers/summary` | 每日汇总：每天的总秒数和条目数 |
| `GET` | `/timers` | 列出条目（分页，可按标签和日期过滤） |
| `GET` | `/timers/{id}` | 获取单个计时条目 |
| `DELETE` | `/timers/{id}` | 删除计时条目（`204 No Content`） |

> **静态路由优先**：`/timers/start`、`/timers/stop`、`/timers/running`、`/timers/summary` 都在 `/timers/{id}` 之前注册，以防止字面路径被捕获为参数化段。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = 运行中
    created_at TEXT NOT NULL
);
```

`end_time` 可为空——`NULL` 表示计时器仍在运行，`NOT NULL` 表示已停止。没有单独的 `status` 列；`end_time` 的存在与否编码了运行状态。

---

## 运行状态：`end_time IS NULL`

计时器的运行状态完全由 `end_time` 列决定：

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // 仍在运行——还没有时长
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` 在 `endTime` 为 `null` 时返回 `true`。`durationSeconds()` 对正在运行的计时器返回 `null`——停止前无法计算时长。对于活跃条目，响应中包含 `"running": true` 和 `"duration_seconds": null`。

---

## 单例计时器：同时只能运行一个

`start()` 在创建新计时器之前检查是否有正在运行的计时器：

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

如果计时器已在运行，抛出 `TimerAlreadyRunningException` → `409 Conflict`。
`end_time` 作为字面 SQL `NULL` 值插入。

运行中计时器的查找：

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — 标准 SQL `NULL` 比较（不是 `= NULL`）。`LIMIT 1` 在不变性被违反时防止返回多行。

---

## 停止计时器：`stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` 查找运行中的计时器，设置 `end_time`，并返回带计算时长的更新条目。如果没有计时器在运行，抛出 `NoRunningTimerException` → `409 Conflict`。

---

## 时长计算：SQL 中的 `strftime('%s', ...)`

对于聚合汇总，时长使用 SQLite 的 `strftime('%s', ...)` 函数在 SQL 中计算，该函数以整数形式返回 datetime 字符串的 Unix epoch 秒数：

```sql
SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds
```

`strftime('%s', ...)` 解析 ISO datetime 字符串（包括 `±HH:MM` 偏移量，会归一化为 UTC）并返回整数 epoch 秒数。两者相减得到精确的秒数差，与 PHP 端的 `getTimestamp()` 差值一致。

> **陷阱 — 不要用 `julianday()` 做秒级精度计算。** 一个诱人的公式是 `CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)`，但 `julianday` 差值是略低于整秒的浮点数，所以 `CAST(... AS INTEGER)` 截断会把 60 秒的条目变成 **59 秒**。改用 `strftime('%s', ...)`（整数 epoch 秒）才是精确的。（由 FT246 `timelog` 示例的 PHPUnit 测试发现）

`SUM(...)` 汇总当天所有已完成的条目。`WHERE end_time IS NOT NULL` 从汇总中过滤掉正在运行的计时器。

PHP 端对单个条目的计算：

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

对于 UTC 时间戳，两种方法产生相同结果。SQL 方法用于聚合（避免获取所有行来求和）；PHP 方法用于单个条目序列化。

---

## 每日汇总聚合

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` 从 `start_time` ISO 字符串中提取日历日期。`GROUP BY day` 将同一天的所有已完成条目分组。`ORDER BY day DESC` 最近的日期排在前面。

`$where` 子句始终以 `['end_time IS NOT NULL']` 开头以排除正在运行的计时器，然后可选地添加 `date(start_time) >= ?` 和 `date(start_time) <= ?` 用于日期范围过滤。

---

## 仅按日期过滤的 `date()` 函数

使用 SQLite 的 `date()` 函数按日历日期过滤条目：

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` 从 ISO datetime 字符串中仅提取 `YYYY-MM-DD`。`= ?` 将提取的日期与过滤值比较。这能正确匹配在指定日期启动的所有条目，无论时间部分如何。

---

## `LIKE` 标签过滤

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` 在 SQLite 的默认排序规则中执行不区分大小写的子串匹配。`$label` 中的特殊字符 `%` 和 `_` 会被解释为 LIKE 通配符——如果需要严格的字面匹配，应对其进行转义。

---

## `GET /timers/running` 响应契约

运行端点无论计时器是否活跃都返回一致的结构：

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — 无活跃计时器。
`running: true, entry: {...}` — 活跃计时器，`end_time: null` 且 `duration_seconds: null`。

这避免了"无运行中计时器"时返回 `404`——`404` 暗示资源不存在，但"运行中计时器"这个概念始终存在（只是为空）。使用 `running: false` 在语义上更清晰。

---

## 相关指南

- [`shift-management.md`](shift-management.md) — 带可空结束时间的班次打卡
- [`scheduled-reminders.md`](scheduled-reminders.md) — 时区感知的 datetime 验证
- [`aggregate-reporting.md`](aggregate-reporting.md) — `GROUP BY date` 聚合模式
- [`handle-timezones.md`](handle-timezones.md) — UTC 存储和时区转换
