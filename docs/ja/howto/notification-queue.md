# ハウツー: 通知キュー API

このガイドでは、管理者がユーザーにターゲット通知を送り、ユーザーが一覧表示・既読・削除できる通知キューを実証します。

## パターン概要

- 管理者は `POST /notifications`（管理者専用）で特定ユーザーに通知を送ります。
- ユーザーは `GET`、`POST /read`、`DELETE` で自分の通知を受信・管理します。
- `unread_count` はすべての一覧レスポンスで返されます。
- `?unread=1` で未読のみの通知にフィルタリングします。
- 既読マークはべき等です（既に既読の通知は 200 を返し、エラーにはなりません）。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## タイプ許可リスト

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

不明なタイプは 422 を返します。バリデーションなしで type に自由形式テキストフィールドを使用しないでください。

## べき等な既読マーク

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // 既に既読 — べき等、成功を返す
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## 未読フィルター

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

クエリパラメーター `?unread=1` でこのパスが有効になります。他の値はすべてを一覧表示します。

## IDOR: ユーザースコープ

すべての読み取り/削除/一覧操作は `user_id` をチェックします:

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

非管理者ユーザーは他のユーザーの通知を読み取り、既読にし、削除することができません。

## 管理者専用送信

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

対象 `user_id` はリクエストボディで指定し、`is_int() && >= 1` としてバリデーションします。

## バリデーションサマリー

| フィールド | ルール |
|---|---|
| `user_id` ボディ | 整数 >= 1（文字列/float ではない） |
| `type` ボディ | info、warning、error、success のいずれか |
| `title` ボディ | 空でない、最大 200 文字 |
| `X-User-Id` ヘッダー | 読み取り/削除に必須; `ctype_digit`、> 0 |
| `X-Admin-Key` ヘッダー | 送信に必須; 空の場合はフェイルクローズド |

## ルート

```
POST   /notifications                  通知を送信する（管理者のみ）
GET    /users/{userId}/notifications   通知を一覧表示する（オーナーまたは管理者）
POST   /notifications/{id}/read        既読にする（オーナーのみ）
DELETE /notifications/{id}             通知を削除する（オーナーまたは管理者）
```

## 関連

- FT214 ソース: `../NENE2-FT/notiflog/`
- 関連: `docs/howto/session-token-management.md`（FT208、admin-key パターン）
