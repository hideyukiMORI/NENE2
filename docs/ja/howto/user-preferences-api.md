# ハウツー: ユーザープリファレンス API

> **FT リファレンス**: FT329 (`NENE2-FT/preflog`) — 型付きバリデーション、デフォルトフォールバック、未知キーの拒否、オーナーのみの変更を持つユーザーごとのプリファレンスストア、20 テスト / 70 アサーション PASS。

このガイドでは、設定が型付きドメイン、デフォルト値、およびカスタマイズ済みかデフォルト値かを区別する `is_default` フラグを持つユーザープリファレンスシステムの構築方法を説明します。

## スキーマ

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

デフォルト値は DB ではなくアプリケーションコードに保持します。

## プリファレンスキーとバリデーション

| キー | 型 | デフォルト | 許可される値 |
|-----|------|---------|----------------|
| `theme` | enum | `"light"` | `light`、`dark`、`system` |
| `language` | enum | `"en"` | `en`、`ja`、`fr` |
| `notifications_enabled` | 真偽値文字列 | `"true"` | `"true"`、`"false"` |
| `items_per_page` | 整数文字列 | `"20"` | `"5"` 〜 `"100"` |
| `timezone` | 文字列 | `"UTC"` | 任意の IANA タイムゾーン |

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `GET` | `/users/{id}/preferences`        | すべて取得する（デフォルト付き） |
| `PUT` | `/users/{id}/preferences/{key}`  | 1 件設定する（オーナーのみ）     |

## すべてのプリファレンスの取得

5 つのキーすべてを返します — 設定されていれば保存された値、そうでなければデフォルト:

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

// theme を dark に設定した後:
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

ユーザーが見つからない場合 → 404。

## プリファレンスの設定

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// 既存を更新（UPSERT）
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // (user_id, pref_key) ごとに 1 行のみ
```

### 未知のキー

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### 無効な値

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // "true"/"false" でなければならない
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // 最大 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // 最小 5
```

### 認可

```php
// 他のユーザーはあなたのプリファレンスを変更できない
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// ユーザーが見つからない
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## 実装パターン

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
        return null;  // 未知のキー
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

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `theme` に任意の文字列を受け入れる | 未知のテーマをレンダリングする際に UI がクラッシュする; enum を検証すること |
| デフォルトを DB に保存する | 新しいユーザーごとに各デフォルトの DB 挿入が必要になる; コードサイドのデフォルトを使用すること |
| プリファレンスが保存されていない場合に空配列を返す | クライアントが「未設定」のケースを処理する必要がある; デフォルト付きですべてのキーを返すこと |
| `is_default` フラグを省略する | クライアントがユーザーの意図とシステムデフォルトを区別できない |
| 他のユーザーのプリファレンスの変更を許可する | プライバシー違反; オーナーチェックは必須 |
| 真偽値プリファレンスに `"yes"/"no"` を受け入れる | 一貫性がない; `"true"/"false"` 文字列に正規化すること |
