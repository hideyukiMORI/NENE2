# 如何处理时区

PHP 的时区处理有几种静默失败模式。本指南涵盖在实际 NENE2 字段试验中遇到的模式和陷阱。

## 创建 `DateTimeImmutable` 时始终指定时区

`new DateTimeImmutable('now')` 使用服务器的 `date.timezone` ini 设置，不同环境间存在差异。始终为服务端时间戳明确传入 `UTC`：

```php
// 脆弱——依赖服务器的 date.timezone
$now = new \DateTimeImmutable('now');

// 正确——始终使用 UTC
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
```

对于存储的时间戳，格式化为 ISO8601 UTC：

```php
$now->format('Y-m-d\TH:i:s\Z') // → "2026-05-20T15:00:00Z"
```

## 明确校验 IANA 时区标识符

PHP 的 `DateTimeZone` 构造函数接受 `"EST"` 等时区缩写而不抛出异常，但这些不是规范的 IANA 标识符。`"America/New_York"` 才是正确的 IANA 形式。

```php
// 这会成功——但 "EST" 不是 IANA 标识符
$tz = new \DateTimeZone('EST'); // 无异常！

// 正确的校验方式：
try {
    $tz = new \DateTimeZone($input);
} catch (\Exception) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}

// 额外的非 IANA 缩写成员检查：
if (!in_array($input, \DateTimeZone::listIdentifiers(), true)) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}
```

不使用 `listIdentifiers()` 检查时，`"EST"`、`"PST"` 等缩写会静默通过。

## 使用 `createFromFormat` 解析本地日期时间字符串

接受用户输入的本地日期时间（不带时区偏移）时，使用带明确格式和时区的 `createFromFormat`：

```php
$tz    = new \DateTimeZone('Asia/Tokyo');
$local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-06-01T10:00:00', $tz);

if ($local === false) {
    // 格式无效——'2026/06/01 10:00'、'2026-06-01' 等全部返回 false
    throw new \InvalidArgumentException('Invalid datetime format. Expected YYYY-MM-DDTHH:mm:ss.');
}
```

优先使用 `createFromFormat` 而非 `new DateTimeImmutable($str, $tz)`——构造函数宽容地静默接受多种格式。

## 将本地时间转换为 UTC 进行存储

```php
$utc = $local->setTimezone(new \DateTimeZone('UTC'));
// 存储为：$utc->format('Y-m-d\TH:i:s\Z')
```

始终在数据库中存储 UTC。将原始时区名称一并存储，以便在获取时重建本地时间。

## 夏令时过渡：模糊的挂钟时间

在"拨回"过渡期间（例如，`America/New_York` 在十一月的第一个周日），某些挂钟时间会出现两次：

- `2026-11-01 01:30 AM` 在 EDT（UTC-4）和 EST（UTC-5）中各出现一次

PHP 通过选择**第一次出现**（夏令时）来解决歧义：

```php
$dt = \DateTimeImmutable::createFromFormat(
    'Y-m-d\TH:i:s',
    '2026-11-01T01:30:00',
    new \DateTimeZone('America/New_York'),
);
// → 05:30 UTC（EDT = UTC-4），而非 06:30 UTC（EST = UTC-5）
```

这符合 IANA 标准。如果应用程序需要区分两次出现（例如日历系统），必须在应用层处理——PHP 未提供选择第二次出现的 API。

## 完整的本地→UTC 转换模式

```php
use Schedule\Event\InvalidTimezoneException;

function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
{
    try {
        $tz = new \DateTimeZone($ianaTimezone);
    } catch (\Exception) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    // 拒绝非 IANA 缩写（例如 "EST"）
    if (!in_array($ianaTimezone, \DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

    if ($local === false) {
        throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
}
```

## 多时区列表查询

列出以 UTC 存储的事件时，在输出时转换为查看者的时区：

```php
$viewTz = QueryStringParser::string($request, 'timezone');

if ($viewTz !== null) {
    try {
        $tz    = new \DateTimeZone($viewTz);
        $local = (new \DateTimeImmutable($event->startUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $data['start_local'] = $local->format('Y-m-d\TH:i:s');
    } catch (\Exception) {
        // 请求的时区无效——静默忽略转换
    }
}
```

---

## SQLite 特有：`datetime('now')` 始终返回 UTC

SQLite 的内置日期/时间函数始终以 **UTC** 运行，不受服务器操作系统时区或 PHP 的 `date.timezone` 设置影响。

```sql
SELECT datetime('now');          -- → "2026-05-27 11:30:00"  （UTC）
SELECT date('now');              -- → "2026-05-27"            （UTC 日期）
SELECT date('now', '+1 day');    -- → "2026-05-28"            （UTC + 1 天）
SELECT datetime('now', '-9 hours'); -- → 近似 JST 本地时间（手动偏移——避免此方式）
```

**这通常是期望的效果**：将时间戳存储为 UTC TEXT，并以 UTC 进行比较。

### 按 UTC "今天"过滤

```sql
-- 今天（UTC）创建的记录
SELECT * FROM events WHERE DATE(created_at) = DATE('now');

-- 未来 30 天内的记录（UTC）
SELECT * FROM reminders WHERE reminder_at <= DATE('now', '+30 days');

-- 特定月份的记录（UTC）
SELECT * FROM logs WHERE STRFTIME('%Y-%m', created_at) = '2026-05';
```

### 陷阱："今天"因时区不同而不同

如果用户在 JST（UTC+9），JST 的"今天"比 UTC 的"今天"早 9 小时开始。SQLite 中的 `DATE('now')` 返回 UTC 日期——这就产生了不匹配。

```php
// 错误：SQLite DATE('now') = UTC 日期，而非用户的本地日期
$rows = $this->db->fetchAll("SELECT * FROM tasks WHERE DATE(due_date) = DATE('now')");

// 正确：在 PHP 中计算用户的"今天"并作为参数传入
$todayUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
$rows = $this->db->fetchAll(
    "SELECT * FROM tasks WHERE DATE(due_date) = ?",
    [$todayUtc],
);
```

对于"今天"意味着 UTC 的服务，`DATE('now')` 没问题。对于面向用户的"今天到期"功能，在 PHP 中使用用户时区计算边界并作为绑定参数传入。

### 使用列值的动态间隔

SQLite 允许将 `date()` 与从列值构建的字符串结合使用：

```sql
-- next_review_at = 基于 interval_days 列的今天
SELECT * FROM cards WHERE next_review_at <= DATE('now');

-- 动态计算下次复习日期（存储结果，不要在 SELECT 中依赖此方式）
SELECT DATE('now', '+' || interval_days || ' days') AS next_date FROM cards;
```

在推进调度的 `UPDATE` 语句中很有用：

```php
$this->db->execute(
    "UPDATE cards SET next_review_at = DATE('now', '+' || interval_days || ' days') WHERE id = ?",
    [$cardId],
);
```

### `STRFTIME` 格式参考

| 模式 | 输出 | 用途 |
|------|------|------|
| `%Y-%m-%d` | `2026-05-27` | 完整日期 |
| `%Y-%m` | `2026-05` | 年月分组 |
| `%Y-%W` | `2026-22` | 年 + **周日开始**的周数（0–53） |
| `%H:%M:%S` | `11:30:00` | 仅时间 |
| `%s` | Unix 时间戳 | 距 epoch 的整数秒数 |

**`%W` 是周日开始**，而非 ISO 8601（周一开始）。对于周一开始的周数，在 PHP 中计算周边界：

```php
// 获取当前 ISO 周的周一
$monday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('Monday this week')
    ->format('Y-m-d');

$sunday = (new \DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');

$rows = $this->db->fetchAll(
    "SELECT * FROM workouts WHERE workout_date BETWEEN ? AND ?",
    [$monday, $sunday],
);
```
