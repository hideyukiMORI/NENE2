# 用户偏好设置管理

用户偏好（Preferences）管理的实现指南。
支持对预定义键集进行带类型验证的存储、更新和重置。

## 概述

- 设置键通过 enum 管理（未知键返回 422）
- 值按键进行类型验证
- 修改其他用户的设置返回 403（所有权检查）
- 未设置的键返回默认值（`is_default: true`）
- DELETE 将设置重置为默认值

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/users/{id}/preferences` | 获取设置列表（全键，含默认值） |
| `PUT` | `/users/{id}/preferences/{key}` | 更新设置值（upsert） |
| `DELETE` | `/users/{id}/preferences/{key}` | 重置设置（恢复为默认值） |

## 数据库设计

```sql
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,   -- 始终以字符串存储
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key), -- 每用户键唯一
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

值始终以 `TEXT` 存储。类型解释由客户端处理
（`items_per_page: "20"` → 前端执行 `parseInt()`）。

## 设置键 enum

```php
enum PreferenceKey: string
{
    case Theme = 'theme';
    case Language = 'language';
    case NotificationsEnabled = 'notifications_enabled';
    case ItemsPerPage = 'items_per_page';
    case Timezone = 'timezone';

    public function defaultValue(): string
    {
        return match ($this) {
            self::Theme => 'light',
            self::Language => 'en',
            self::NotificationsEnabled => 'true',
            self::ItemsPerPage => '20',
            self::Timezone => 'UTC',
        };
    }

    public function validate(string $value): bool
    {
        return match ($this) {
            self::Theme => in_array($value, ['light', 'dark', 'system'], true),
            self::Language => preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) === 1,
            self::NotificationsEnabled => in_array($value, ['true', 'false'], true),
            self::ItemsPerPage => ctype_digit($value) && (int) $value >= 5 && (int) $value <= 100,
            self::Timezone => strlen($value) <= 64 && strlen($value) > 0,
        };
    }
}
```

## GET /users/{id}/preferences 响应

```json
{
  "preferences": [
    {"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-21T10:00:00+00:00"},
    {"key": "language", "value": "en", "is_default": true, "updated_at": null},
    {"key": "notifications_enabled", "value": "true", "is_default": true, "updated_at": null},
    {"key": "items_per_page", "value": "20", "is_default": true, "updated_at": null},
    {"key": "timezone", "value": "UTC", "is_default": true, "updated_at": null}
  ]
}
```

返回全部键（已保存的返回其值，未保存的返回默认值）。

## Upsert 模式

```php
public function upsertPreference(int $userId, string $key, string $value, string $now): void
{
    $existing = $this->findPreference($userId, $key);
    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = ? WHERE user_id = ? AND pref_key = ?',
            [$value, $now, $userId, $key]
        );
    } else {
        $this->executor->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, ?)',
            [$userId, $key, $value, $now]
        );
    }
}
```

结合 `UNIQUE(user_id, pref_key)` 约束，保证每用户每键只有一行。

## 所有权检查（IDOR 防止）

```php
$actorId = (int) $request->getHeaderLine('X-User-Id');
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
}
```

其他用户无法修改或删除设置。读取对所有人开放（设置通常是公开的）。

## DELETE = 重置（物理删除）

DELETE 从数据库中物理删除设置行，GET 将再次返回默认值：

```php
$this->repository->deletePreference($userId, $prefKey->value);
return $this->responseFactory->create([
    'key' => $prefKey->value,
    'value' => $prefKey->defaultValue(),
    'is_default' => true,
], 200);
```

未设置时（第一次执行 DELETE）也返回 200（幂等性）。

## 未知键的响应

```json
{
  "error": "unknown preference key",
  "valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]
}
```

返回有效键列表，提高 API 的自描述性。

## 扩展模式

- **分类管理**：添加 `PreferenceCategory` enum，按 UI、通知、显示等进行分组
- **按用户类型设置默认值**：通过 `defaultValue(UserType $type)` 进行条件分支
- **审计日志**：使用 `updated_at` + 变更历史表跟踪设置修改
- **批量更新**：使用 `PATCH /users/{id}/preferences` 一次更新多个设置
