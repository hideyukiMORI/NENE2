# 如何校验带时区的 ISO 8601 日期时间

接受用户控制的日期时间字符串需要谨慎校验。本指南涵盖两个最重要的陷阱：**PHP 静默接受无效的时区偏移**，以及**字符串比较在不同时区偏移间失败**。

---

## V::isoDatetime — 格式校验

```php
V::isoDatetime(mixed $raw): ?string
```

校验 `±HH:MM` 偏移格式的日期时间字符串：

```
✅ 2024-01-15T12:30:00+09:00   （JST）
✅ 2024-06-01T00:00:00+00:00   （UTC）
✅ 2024-12-31T23:59:59-05:00   （EST）
✅ 2026-06-15T09:00:00-14:00   （UTC−14，豪兰岛）
✅ 2026-06-15T09:00:00+14:00   （UTC+14，基里巴斯）

❌ 2024-01-15                   （仅日期，无时间）
❌ 2024-01-15T12:00:00Z         （'Z' 后缀，非 ±HH:MM）
❌ 2024-01-15T12:00:00          （完全没有偏移）
❌ 2024-02-30T00:00:00+00:00   （2 月 30 日不存在）
❌ 2024-13-01T00:00:00+00:00   （13 月不存在）
❌ 2026-06-15T09:00:00+25:00   （无效偏移——超过 +14:00）
```

### 实现

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // 严格正则：必须有 ±HH:MM，不接受 Z，不接受裸时间
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // 校验偏移范围：有效 UTC 偏移为 −14:00 … +14:00。
    // PHP 的 DateTimeImmutable 会静默接受 +25:00 等无效偏移。
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable 保留输入时区——避免 strtotime + date()
    // 会静默地以服务器本地时区重新格式化。
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // 往返比较捕获溢出日期（2 月 30 日会滚到 3 月 1 日等）
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### 为什么不用 `strtotime` + `date()`？

```php
// ❌ 错误——date() 使用服务器本地时区
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// 如果服务器是 UTC：'2024-01-15T03:30:00+00:00'——时区丢失了！
```

```php
// ✅ 正确——DateTimeImmutable 保留原始偏移
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — 跨时区未来时间检查

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

只有当日期时间**严格晚于** `$now` 时才返回已校验的字符串。

### 关键 Bug：字符串比较在跨时区场景下失效

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 过去 1 小时
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ 错误：字符串比较认为是未来（"T18" > "T10"）
$pastJst > $now  // → TRUE   ← 错！它在过去！

// ✅ 正确：DateTimeImmutable 比较先归一化到 UTC
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓（正确判断为过去）
```

负偏移也会出现相反的错误：

```php
// EST 08:00 = UTC 13:00 → 未来 3 小时
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ 错误：字符串比较认为是过去（"T08" < "T10"）
$futureEst > $now  // → FALSE  ← 错！它在未来！

// ✅ 正确：对象比较
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓（正确判断为未来）
```

### 实现

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // 对象比较先将两者归一化到 UTC 再进行比较。
    return $dtObj > $nowObj ? $dt : null;
}
```

### 在路由处理器中使用

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // 使用 DateTimeImmutable 获取保留时区的"现在"
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt 现在可以安全存储——保留了提交的原始字符串和时区。
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## 时区保留

原样存储 `remind_at`（或任何用户提交的日期时间）——不转换为 UTC。

```php
// ✅ 原样存储已校验的字符串
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// 其中 :remind_at = '2026-06-15T09:00:00+09:00'

// 在 API 响应中原样返回
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

这尊重用户的意图，避免隐式时区转换。如果应用程序需要 UTC 归一化以在 SQL 中进行排序/比较，可在写入时计算一个独立的 `remind_at_utc` 列。

---

## 已校验输入 → 安全 SQL

经过 `V::isoDatetime()` / `V::futureDatetime()` 校验后，字符串可通过参数化查询安全插入。绝不将原始日期时间字符串插值到 SQL 中。

```php
// ✅ 安全——预校验，参数化
$stmt->execute(['remind_at' => $remindAt]);

// ❌ 危险——原始用户输入被插值
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## 相关资料

- FT181 — reminderlog：ISO 8601 日期时间校验与时区感知 API
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — 互联网上的日期和时间
- [IANA 时区数据库](https://www.iana.org/time-zones) — UTC 偏移参考
- `docs/howto/json-merge-patch.md` — created_at 也使用 isoDatetime
