# How-to: コメントスレッド API

このガイドでは、ページネーション・著者のみによる削除・管理者オーバーライドを備えた、リソーススコープ付きのコメントスレッドを示します。

## パターン概要

- コメントはリソース（整数 ID で識別）に紐付きます。
- 認証済みユーザーは任意のリソースにコメントを投稿できます。
- コメントは公開で読み取り可能です（一覧表示に認証不要）。
- 著者は自分のコメントを削除できます。管理者はどれでも削除可能です。
- `limit` と `offset` クエリパラメーターによるページネーション。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## ページネーションパターン

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

レスポンスには `total`（リソースの全コメント数）、`limit`、`offset` が含まれ、クライアントがページネーションコントロールを構築できます:

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## limit のクランプ

無効または範囲外の limit/offset 値は静かに安全なデフォルト値にクランプされます:

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

クエリ文字列での ReDoS を避けるため `ctype_digit()` を使用しています。

## IDOR: 著者のみの削除

非管理者ユーザーは自分のコメントしか削除できません。他人のコメントを削除しようとすると（403 ではなく）404 が返ります:

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## リソース分離

すべてのクエリに `WHERE resource_id = :rid` を含め、リソース 1 のコメントがリソース 2 と混在しないようにします。

## バリデーションルール

| Field | Rule |
|---|---|
| `X-User-Id` | POST/DELETE で必須。`ctype_digit`、>0 |
| `body` | 空でないこと、最大 2000 文字 |
| `{resourceId}` パス | `ctype_digit`、最大 18 文字、>0。それ以外は 404 |
| `limit` クエリ | 1–100 の整数。デフォルト 20 |
| `offset` クエリ | 非負整数。デフォルト 0 |

## ルート

```
POST   /resources/{resourceId}/comments  コメントを投稿（X-User-Id 必須）
GET    /resources/{resourceId}/comments  コメント一覧（ページネーション、公開）
DELETE /comments/{id}                   コメントを削除（著者または管理者）
```

## 関連

- FT211 ソース: `../NENE2-FT/commentlog/`
- 関連: `docs/howto/note-taking.md`（FT202、ノート CRUD）
- 関連: `docs/howto/leaderboard-ranking.md`（FT206、リソーススコープデータ）
