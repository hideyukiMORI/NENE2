# ハウツー: コメントスレッド API

このガイドでは、ページネーション、著者のみの削除、管理者オーバーライドを持つリソーススコープのコメントスレッドを説明します。

## パターンの概要

- コメントはリソース（整数 ID で識別）に属します。
- 認証済みのどのユーザーも任意のリソースにコメントを投稿できます。
- コメントはパブリックに読み取れます（一覧表示に認証不要）。
- 著者は自分のコメントを削除できます。管理者は任意のコメントを削除できます。
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

レスポンスには `total`（リソースのすべてのコメント数）、`limit`、`offset` が含まれ、クライアントがページネーションコントロールを構築できます:

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## 制限クランプ

無効または範囲外の limit/offset 値は安全なデフォルトにサイレントにクランプされます:

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

クエリ文字列での ReDoS を避けるために `ctype_digit()` を使用します。

## IDOR: 著者のみの削除

非管理者ユーザーは自分のコメントのみ削除できます。別のユーザーのコメントを削除しようとすると 404 が返ります（403 ではない）:

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## リソース分離

すべてのクエリに `WHERE resource_id = :rid` が含まれ、リソース 1 のコメントがリソース 2 と混在しないことを確保します。

## バリデーションルール

| フィールド | ルール |
|---|---|
| `X-User-Id` | POST/DELETE に必須。`ctype_digit`、>0 |
| `body` | 空でない、最大 2000 文字 |
| `{resourceId}` パス | `ctype_digit`、最大 18 文字、>0。それ以外は 404 |
| `limit` クエリ | 整数 1〜100。デフォルト 20 |
| `offset` クエリ | 非負整数。デフォルト 0 |

## ルート

```
POST   /resources/{resourceId}/comments  コメントを投稿する（X-User-Id 必須）
GET    /resources/{resourceId}/comments  コメントを一覧表示する（ページネーション、パブリック）
DELETE /comments/{id}                   コメントを削除する（著者または管理者）
```

## 関連情報

- FT211 ソース: `../NENE2-FT/commentlog/`
- 関連: `docs/howto/note-taking.md`（FT202、ノート CRUD）
- 関連: `docs/howto/leaderboard-ranking.md`（FT206、リソーススコープデータ）
