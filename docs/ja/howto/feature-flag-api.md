# ハウツー: フィーチャーフラグ API

> **FT リファレンス**: FT313 (`NENE2-FT/flaglog`) — フィーチャーフラグ管理: 環境ごとのフラグ、rollout_percent による段階的ロールアウト、ユーザーごとのオーバーライド、オーバーライド解決付き評価エンドポイント、snake_case キーバリデーション、18 テスト / 29 アサーション PASS。

このガイドでは、環境ごとの設定、パーセンテージによる段階的ロールアウト、ユーザーごとのオーバーライドをサポートするフィーチャーフラグシステムの構築方法を示します。

## スキーマ

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` は `^[a-z][a-z0-9_]*$`（snake_case）に一致する必要があります。`rollout_percent` は 0〜100 です。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `PUT` | `/flags/{key}` | フラグを作成または更新する |
| `GET` | `/flags` | すべてのフラグを一覧表示する（オプション `?environment=`） |
| `GET` | `/flags/{key}/evaluate` | ユーザーのフラグを評価する（`?user_id=`） |
| `PUT` | `/flags/{key}/overrides/{userId}` | ユーザーごとのオーバーライドを設定する |
| `DELETE` | `/flags/{key}/overrides/{userId}` | ユーザーごとのオーバーライドを削除する |

## フラグ UPSERT — PUT /flags/{key}

```php
// リクエストボディ
{
    "enabled": true,
    "rollout_percent": 50,   // オプション、デフォルト 100
    "environment": "staging" // オプション、デフォルト "production"
}

// レスポンス 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

同じエンドポイントが作成または更新（`key + environment` による UPSERT）を行います。異なる値で `PUT` を 2 回送信するとフラグが更新されます。

## キーバリデーション

```php
// 有効なキー（snake_case: a-z、0-9、アンダースコア、文字で始まる）
dark_mode, beta_ui, new_feature_v2

// 無効 — 422 を返す
Dark-Mode   // 大文字 + ハイフン
123flag     // 数字で始まる
my flag     // スペース
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## ロールアウトパーセントバリデーション

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## 環境ごとのフラグ

```php
// 同じキー、異なる環境
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// 環境でフィルタリングして一覧表示
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## 評価 — ロールアウト + オーバーライド

```
GET /flags/{key}/evaluate?user_id={userId}
```

解決順序:
1. **オーバーライドが優先**: `(key, environment, user_id)` の `flag_overrides` 行が存在する場合 → オーバーライド値を使用
2. **フラグ無効**: `enabled = false` の場合 → ロールアウトに関わらず `false` を返す
3. **ロールアウトチェック**: `user_id` を決定論的にハッシュ化 → `rollout_percent` と比較

```php
// 1. オーバーライドを確認
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. フラグ無効
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. ロールアウトパーセント
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

レスポンス:
```json
{"enabled": true, "override": null}   // ロールアウト判定
{"enabled": true, "override": true}   // オーバーライドで有効
{"enabled": false, "override": false} // オーバーライドで無効
```

## ユーザーごとのオーバーライド

```php
// フラグがオフ / ロールアウト 0% でも特定ユーザーに対して有効化
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// フラグがオン / ロールアウト 100% でも特定ユーザーに対して無効化
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// オーバーライドを削除 — グローバルフラグ + ロールアウトロジックに戻す
DELETE /flags/my_flag/overrides/alice
```

オーバーライドには `enabled` フィールド（boolean）が必要です。フィールドが不在 → 422。
存在しないフラグへのオーバーライド → 404。
存在しないオーバーライドの削除 → 404。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 任意のキーフォーマットを許可する（例: ハイフン、大文字） | チーム間でキーが不整合になる。コードで grep/参照しにくい |
| rollout percent が 100 を超える | ロジックエラー。110% のロールアウトは段階的なつもりでも常に有効になる |
| 環境分離なし | staging フラグが production に漏れる。カナリアデプロイが壊れる |
| `user_id` チェックなしで評価する | `crc32(null)` や空文字列で決定論的だが間違ったバケット分けになる |
| 存在しないフラグへの評価で 200 を返す | 呼び出し元がフラグが存在すると思い込む。アラートを上げる代わりにサイレントに無効として扱う |
| TTL なしのメモリ/キャッシュにグローバルフラグ状態を保持する | ロールアウトパーセント変更後に古いフラグが残る。変更が伝播しない |
