# User Preferences Management

ユーザー設定（Preferences）管理の実装ガイド。
事前定義されたキーセットに対して型バリデーション付きで設定値を保存・更新・リセットできる。

## 概要

- 設定キーは enum で管理（未知のキーは 422）
- 値はキーごとに型バリデーション
- 他ユーザーの設定変更は 403（所有権チェック）
- 未設定キーはデフォルト値を返す（`is_default: true`）
- DELETE でデフォルト値にリセット

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `GET` | `/users/{id}/preferences` | 設定一覧取得（全キー、デフォルト含む） |
| `PUT` | `/users/{id}/preferences/{key}` | 設定値更新（upsert） |
| `DELETE` | `/users/{id}/preferences/{key}` | 設定リセット（デフォルトに戻す） |

## データベース設計

```sql
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,   -- 常に文字列として保存
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key), -- ユーザーごとにキー一意
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

値は常に `TEXT` として保存する。型の解釈はクライアント側で行う
（`items_per_page: "20"` → フロントで `parseInt()`）。

## 設定キー enum

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

## GET /users/{id}/preferences レスポンス

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

全キーを返す（保存済みのものはその値、未保存のものはデフォルト値）。

## Upsert パターン

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

UNIQUE(user_id, pref_key) 制約と組み合わせて、1 ユーザー 1 キーにつき 1 行を保証する。

## 所有権チェック（IDOR 防止）

```php
$actorId = (int) $request->getHeaderLine('X-User-Id');
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
}
```

他ユーザーの設定を変更・削除できない。読み取りは誰でも可能（設定は通常 public）。

## DELETE = リセット（物理削除）

DELETE は設定行を DB から削除し、GET では再びデフォルト値が返る:

```php
$this->repository->deletePreference($userId, $prefKey->value);
return $this->responseFactory->create([
    'key' => $prefKey->value,
    'value' => $prefKey->defaultValue(),
    'is_default' => true,
], 200);
```

未設定時（初めて DELETE した場合）も 200 を返す（冪等性）。

## 不明なキーへのレスポンス

```json
{
  "error": "unknown preference key",
  "valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]
}
```

有効なキー一覧を返すことで API の自己説明性を高める。

## 拡張パターン

- **カテゴリ分け**: `PreferenceCategory` enum を追加して UI・通知・表示などでグループ化
- **ユーザー種別ごとのデフォルト**: `defaultValue(UserType $type)` で条件分岐
- **監査ログ**: `updated_at` + 変更履歴テーブルで設定変更を追跡
- **一括更新**: `PATCH /users/{id}/preferences` で複数設定を一度に更新
