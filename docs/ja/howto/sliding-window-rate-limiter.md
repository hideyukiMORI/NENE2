# ハウツー: スライディングウィンドウレートリミッター

## 概要

このガイドでは、NENE2 を使ったユーザーごと・エンドポイントごとのスライディングウィンドウレートリミッターの構築方法を説明します。リクエストはローリングタイムウィンドウ内でカウントされ、制限に達するとそれ以降のリクエストは `429 Too Many Requests` で拒否されます。

**参照実装**: `../NENE2-FT/ratelog/`

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

`(user_id, endpoint, created_at)` のインデックスにより、大規模でも COUNT クエリが高速になります。

---

## ルートテーブル

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/rate/check` | ユーザー | リクエストを記録する; 制限超過時は 429 を返す |
| `GET` | `/rate/status` | ユーザー | ユーザー/エンドポイントの現在の使用状況 |
| `DELETE` | `/rate/reset/{userId}` | 管理者 | ユーザーのカウンターをリセットする |

---

## コアアルゴリズム

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60s
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**スライディングウィンドウ**: 各 `check()` は現在の時刻から正確に `WINDOW_SECONDS` さかのぼって確認するため、古いイベントは自然にスコープ外に落ちます。

---

## フェイルクローズパターンによる管理者リセット

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // フェイルクローズ: 未設定キーはすべての管理者アクセスをブロック
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

ユーザーのすべてのカウンターをリセット（すべてのエンドポイント）:
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

特定のエンドポイントのみリセット:
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## パスパラメーター抽出（Router::param() なし）

インストール済みバージョンで `Router::param()` が利用できない場合は、属性を直接使用してください:

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## バリデーション

- `endpoint`: 空でない文字列、最大 128 文字
- `X-User-Id`: `ctype_digit()` + 正の整数
- パス `userId`: `ctype_digit()` + 正の整数（失敗 → 404）
- 管理者キー: `hash_equals()` 比較（失敗 → 403）

---

## HTTP ステータスコード

| 状況 | ステータス |
|------|----------|
| リクエスト許可 | 200 |
| ステータス取得済み | 200 |
| カウンターリセット済み | 200 |
| X-User-Id なし | 400 |
| ボディなし | 400 |
| endpoint が空または欠如 | 422 |
| endpoint が長すぎる | 422 |
| 管理者キーなし | 403 |
| 誤った管理者キー | 403 |
| パスの無効な userId | 404 |
| レート制限超過 | 429 |

---

## カバーされた ATK 攻撃パターン

| ATK | パターン | 防御 |
|-----|---------|------|
| ATK-01 | X-User-Id 欠如 | メッセージ付き 400 |
| ATK-02 | 空の endpoint 文字列 | 422 バリデーション |
| ATK-03 | 129 文字の endpoint（DoS） | 長さ制限 422 |
| ATK-04 | endpoint への SQL インジェクション | パラメーター化クエリ |
| ATK-05 | 非管理者のリセット試行 | 403 フェイルクローズ |
| ATK-06 | 誤った管理者キー | 403 hash_equals() |
| ATK-07 | パスの負の userId | 404 |
| ATK-08 | ゼロ userId | 404 |
| ATK-09 | 非数字 userId（`abc`） | 404 ctype_digit |
| ATK-10 | endpoint パラメーターなしのステータス | 422 |
| ATK-11 | ボディなしのチェック | 400 |
| ATK-12 | endpoint キーのないボディ | 422 |

---

## 注意事項

- **並行性**: スライディングウィンドウには小さな TOCTOU ウィンドウがあります。高並行性の本番環境では、アトミックカウンター（Redis INCR + EXPIRE）またはデータベースレベルのロックを検討してください。
- **クロックスキュー**: すべてのタイムスタンプは UTC を使用して DST やタイムゾーンの問題を避けてください。
- **ストレージの増大**: 古いイベントが蓄積されます。定期的なクリーンアップジョブを追加してください: `DELETE FROM rate_events WHERE created_at < :cutoff`。
