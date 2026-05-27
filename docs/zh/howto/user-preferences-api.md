# 操作指南：用户偏好设置 API

> **FT 参考**：FT329（`NENE2-FT/preflog`）— 带类型值验证、默认回退、未知键拒绝、仅所有者可修改的用户偏好存储，20 tests / 70 assertions 全部 PASS。

本指南展示如何构建用户偏好设置系统，其中设置具有类型域、默认值，以及用于区分自定义值与默认值的 `is_default` 标志。

## 数据库结构

```sql
CREATE TABLE user_preferences (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    pref_key   TEXT    NOT NULL,
    pref_value TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, pref_key)
);
```

默认值保存在应用代码中，而非数据库中。

## 偏好键与验证

| 键 | 类型 | 默认值 | 允许值 |
|---|---|---|---|
| `theme` | enum | `"light"` | `light`、`dark`、`system` |
| `language` | enum | `"en"` | `en`、`ja`、`fr` |
| `notifications_enabled` | 布尔字符串 | `"true"` | `"true"`、`"false"` |
| `items_per_page` | 整数字符串 | `"20"` | `"5"` – `"100"` |
| `timezone` | string | `"UTC"` | 任何 IANA 时区 |

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/users/{id}/preferences` | 获取全部（含默认值） |
| `PUT` | `/users/{id}/preferences/{key}` | 设置单项（仅所有者） |

## 获取全部偏好设置

返回全部 5 个键——已存储则返回存储值，否则返回默认值：

```php
GET /users/1/preferences
→ 200
{
  "user_id": 1,
  "preferences": [
    {"key": "theme",                 "value": "light", "is_default": true,  "updated_at": null},
    {"key": "language",              "value": "en",    "is_default": true,  "updated_at": null},
    {"key": "notifications_enabled", "value": "true",  "is_default": true,  "updated_at": null},
    {"key": "items_per_page",        "value": "20",    "is_default": true,  "updated_at": null},
    {"key": "timezone",              "value": "UTC",   "is_default": true,  "updated_at": null}
  ]
}

// 将 theme 设置为 dark 后：
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

用户不存在 → 404。

## 设置偏好项

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// 更新已有项（UPSERT）
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // 每个 (user_id, pref_key) 只有一行
```

### 未知键

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### 无效值

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // 必须是 "true"/"false"
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // 最大 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // 最小 5
```

### 权限验证

```php
// 其他用户无法修改你的偏好设置
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// 用户不存在
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## 实现模式

```php
private const SCHEMA = [
    'theme'                 => ['type' => 'enum',    'values' => ['light','dark','system']],
    'language'              => ['type' => 'enum',    'values' => ['en','ja','fr']],
    'notifications_enabled' => ['type' => 'bool_str','values' => ['true','false']],
    'items_per_page'        => ['type' => 'int_str', 'min' => 5, 'max' => 100],
    'timezone'              => ['type' => 'string'],
];

private function validate(string $key, string $value): ?string
{
    $schema = self::SCHEMA[$key] ?? null;
    if ($schema === null) {
        return null;  // 未知键
    }

    return match ($schema['type']) {
        'enum'     => in_array($value, $schema['values'], true) ? $value : throw ValidationException,
        'bool_str' => in_array($value, ['true','false'], true) ? $value : throw ValidationException,
        'int_str'  => $this->validateIntStr($value, $schema['min'], $schema['max']),
        default    => $value,
    };
}
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 对 `theme` 接受任意字符串 | UI 渲染未知主题时崩溃；需要验证枚举值 |
| 将默认值存入数据库 | 每个新用户对每个默认值都需要 DB 插入；使用代码端默认值 |
| 无偏好存储时返回空数组 | 客户端必须处理"未设置"情况；应返回含默认值的全部键 |
| 省略 `is_default` 标志 | 客户端无法区分用户意图与系统默认值 |
| 允许修改其他用户的偏好设置 | 隐私侵犯；所有者检查是必须的 |
| 对布尔偏好接受 `"yes"/"no"` | 不一致；应规范化为 `"true"/"false"` 字符串 |
