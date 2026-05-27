# 操作指南：时区感知的事件调度

> **FT 参考**：FT286（`NENE2-FT/schedulelog`）— 时区感知调度：UTC 存储 + 本地时间转换，通过 `DateTimeZone::listIdentifiers()` 进行 IANA 时区验证，`InvalidTimezoneException`，动态 `?timezone` 查询参数，19 tests / 39 assertions 全部 PASS。

本指南展示如何构建一个将时间存储为 UTC 并以客户端请求的任何时区呈现的事件调度 API。

## 为什么存储 UTC？

UTC 是通用参考基准。本地时间存在歧义（夏令时变化、时区规则变更），且因客户端位置而异。通过存储 UTC：
- 排序和比较始终正确
- 客户端可以在其本地时区显示
- 夏令时转换不会在历史数据中产生歧义

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- 事件创建者的 IANA 时区
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601: 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- 本地 ISO 8601: 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

`start_utc` 和 `start_local` 都存储。`start_utc` 是权威值；`start_local` 是创建者时区的便利缓存。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/events` | 创建事件（时区 + 本地开始时间 → UTC） |
| `GET` | `/events` | 列出事件（可选 `?timezone=America/New_York`） |
| `GET` | `/events/{id}` | 获取事件（可选 `?timezone=`） |

## IANA 时区验证

PHP 的 `DateTimeZone` 构造函数会静默接受某些无效标识符。需要显式验证：

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        // PHP 在某些版本中接受无效缩写（如 "EST"）——
        // 需要明确对照标准 IANA 列表进行验证。
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` 返回 PHP 编译时内置的 IANA 标识符列表。非 IANA 字符串（如 `EST`、`GMT+5`）会被拒绝。

## 创建事件：本地时间 → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Unknown timezone: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Cannot parse datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## 列出事件：动态时区转换

`?timezone=` 查询参数实时将所有事件转换为客户端的时区：

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // 无效的视图时区：静默回退到 UTC
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

无效的 `?timezone=` 值静默回退到存储的 `start_local`，而不是返回错误——这是只读视图的合理设计选择。

## UTC 格式：带 Z 后缀的 ISO 8601

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ 字面 Z
}
```

`Z` 后缀明确表示 UTC（符合 ISO 8601 / RFC 3339）。使用 `+00:00` 或省略偏移量也是可接受的替代方案，但 `Z` 更简洁且被普遍识别。

## 夏令时安全转换

```
示例：Asia/Tokyo 是 UTC+9（无夏令时）
本地时间：2026-05-20T10:00:00  Asia/Tokyo
UTC：     2026-05-20T01:00:00Z

示例：America/New_York（有夏令时）
本地时间：2026-05-20T10:00:00  America/New_York（夏季 EDT = UTC-4）
UTC：     2026-05-20T14:00:00Z

本地时间：2026-01-20T10:00:00  America/New_York（冬季 EST = UTC-5）
UTC：     2026-01-20T15:00:00Z
```

带命名 IANA 时区的 `DateTimeImmutable` 自动处理夏令时。它使用该特定日期有效的偏移量，而非固定偏移量。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 不带时区列存储本地时间 | 无法在事后转换为 UTC；夏令时变更后历史数据变得模糊 |
| 接受 `EST`、`PST`、`GMT+5` 作为时区 | 模糊的缩写；有些对应多个 IANA 时区；`DateTimeZone::listIdentifiers()` 拒绝这些 |
| 使用 `new DateTimeZone($tz)` 而不检查 `listIdentifiers()` | PHP 会静默接受某些无效或已废弃的标识符；标准验证能捕获它们 |
| 存储 UTC 偏移量（`+09:00`）而非 IANA 名称 | 仅靠偏移量无法处理夏令时；`Asia/Tokyo` 始终是 +9，但 `America/New_York` 会变化 |
| 按 `start_local` 排序事件 | 本地时间的字典序排序忽略时区差异；始终按 `start_utc` 排序 |
| 每次查询都转换时区 | 大型数据集代价高昂；考虑缓存或预计算常用视图时区 |
| GET 请求中无效的 `?timezone=` 返回 422 | 只读查询应优雅降级；回退到 UTC 而不是报错 |
| 使用 `date()` 代替 `DateTimeImmutable` | `date()` 使用服务器的默认时区；带显式时区的 `DateTimeImmutable` 更可预测 |
