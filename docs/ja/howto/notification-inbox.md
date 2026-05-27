# ハウツー: 通知受信トレイ API

> **FT リファレンス**: FT271 (`NENE2-FT/notificationlog`) — 通知受信トレイ: タイプ許可リスト付き通知作成、ユーザーごとの IDOR 保護（404、403 ではない）、管理者フェイルクローズドパターン、一括既読、is_read べき等性、PDO::PARAM_INT バインディングによるページネーションクランプ、31 テスト / 98 アサーション PASS。
>
> FT222 (`NENE2-FT/notificationlog`) でも同じパターンの VULN アセスメントが検証済みです。

このガイドでは NENE2 を使ってタイプ許可リスト付きプッシュ通知、ユーザーごとの IDOR 保護、一括既読機能を持つ通知受信トレイシステムの構築方法を解説します。

## 機能

- 管理者のみの通知作成とタイプ許可リスト
- ユーザーごとの IDOR 保護: ユーザーは自分の通知のみ閲覧可能（未認可アクセスには 404）
- オーナーシップ検証付きの単一および一括既読
- 一覧取得ごとに未読件数を返す
- オプションの未読のみフィルターとページネーション
- 管理者フェイルクローズド

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

別の `users` テーブルはありません — API は `X-User-Id` ヘッダーを信頼します（本番では実際の認証に置き換えてください）。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/notifications` | 管理者 | ユーザーへの通知を作成する |
| `GET` | `/users/{userId}/notifications` | 本人 / 管理者 | 通知を一覧表示する |
| `POST` | `/notifications/{id}/read` | 本人 / 管理者 | 1 件を既読にする |
| `POST` | `/users/{userId}/notifications/read-all` | 本人 / 管理者 | すべてを既読にする |

## タイプ許可リスト

自由形式のタイプ文字列はインジェクション攻撃と列挙攻撃を防ぐために拒否されます:

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

ルートハンドラーは DB アクセスの前にバリデーションします:

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## IDOR 保護

ユーザーは自分の通知のみ閲覧できます。未認可アクセスにはユーザー ID 列挙を防ぐために 404（403 ではなく）を返します:

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

既読マークもアクション前にオーナーシップを検証します:

```php
// POST /notifications/{id}/read ハンドラー
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR: オーナーまたは管理者のみ既読にできる
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## 管理者フェイルクローズド

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // フェイルクローズド: キーが設定されていない場合は管理者なし
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## ページネーション

`limit` と `offset` はリポジトリでクランプされます — クライアントからの生の値は信頼しません:

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

PDO の整数バインディングが LIMIT / OFFSET での SQL インジェクションを防ぎます:

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## 既読マークのべき等性

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

ルートハンドラーは `ok` と `already_read` の両方に 200 を返します — エンドポイントを副作用なしで複数回呼び出せます。

## セキュリティパターン

| パターン | 実装 |
|---------|------|
| **タイプ許可リスト** | `in_array($type, ALLOWED_TYPES, true)` — 厳密な一致 |
| **IDOR → 404** | ユーザー/通知の存在を隠すために 404（403 ではなく）を返す |
| **オーナーシップ検証** | 通知を取得し、既読前に `user_id` を確認する |
| **管理者フェイルクローズド** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | パスパラメーター ID バリデーション — ReDoS セーフ |
| **ページネーションクランプ** | `max(1, min(100, $limit))` + `PDO::PARAM_INT` バインディング |
| **`is_int()` + `> 0`** | 厳密な user_id チェック — float、文字列、負の値を拒否 |

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 自由形式の `type` 文字列を受け入れる | 未バリデーションのタイプが受信トレイを汚染する; 意味のあるカテゴリでフィルタリング不能 |
| 未認可の通知アクセスに 403 を返す | 通知またはユーザーが存在するかどうかを明かす — IDOR 情報漏洩 |
| オーナーシップ確認前に既読で 404 を返す | 攻撃者が通知の存在と誰かに属していることを知る |
| 空の `adminKey` が「管理者許可」を意味するようにする | フェイルオープン; キーが設定されていない場合、どのリクエストも管理者になる |
| クエリ文字列からの生の `limit` を信頼する | `limit=999999` のリクエストがフルテーブルスキャンを引き起こす |
| LIMIT/OFFSET に文字列補間を使う | 未バリデーションの入力で `"LIMIT {$limit}"` が SQL インジェクションを可能にする |
