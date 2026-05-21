# Activity Feed (Follow-based, Cursor Pagination)

フォローベースのアクティビティフィード API を NENE2 で実装する方法。
ユーザーが他のユーザーをフォローし、フォロー相手の公開アクティビティをリアルタイムで受け取れる仕組み。

---

## エンドポイント一覧

| Method | Path | 説明 | 認証 |
|---|---|---|---|
| `GET` | `/feed` | 自分＋フォロー中ユーザーのフィード取得 | 必須 |
| `POST` | `/users/{userId}/activities` | アクティビティ投稿 | 本人のみ |
| `GET` | `/users/{userId}/activities` | ユーザーのアクティビティ一覧 | 必須 |
| `POST` | `/users/{followeeId}/follow` | フォロー（冪等: 201/200） | 必須 |
| `DELETE` | `/users/{followeeId}/follow` | フォロー解除 | 必須 |

---

## DB スキーマ

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE follows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL,
    followee_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (follower_id, followee_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (followee_id) REFERENCES users(id)
);

CREATE TABLE activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('post', 'like', 'comment', 'follow', 'share')),
    object_id INTEGER,
    object_type TEXT,
    summary TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    FOREIGN KEY (actor_id) REFERENCES users(id)
);
```

---

## カーソルページネーション

オフセットページネーションではなく、`id` を cursor として使用することで、
大量データでも高速かつ挿入に強いページネーションを実現する。

```
GET /feed?limit=20
→ { items: [...], next_cursor: 42 }

GET /feed?limit=20&before_id=42
→ { items: [...], next_cursor: 15 }

GET /feed?limit=20&before_id=15
→ { items: [...], next_cursor: null }  ← 末尾
```

`next_cursor` が `null` のときはそれ以上アイテムがない。

SQL の実装:

```sql
-- 初回（cursor なし）
SELECT a.*, u.name as actor_name
FROM activities a
JOIN users u ON a.actor_id = u.id
WHERE (a.actor_id IN (SELECT followee_id FROM follows WHERE follower_id = ?)
   OR a.actor_id = ?)
  AND a.is_public = 1
ORDER BY a.id DESC
LIMIT ?

-- 続きの取得（cursor あり）
... AND a.id < ?
ORDER BY a.id DESC LIMIT ?
```

---

## フォロー（冪等）

既にフォロー済みの場合は `200 OK`、新規フォローの場合は `201 Created` を返す。
フロントエンドは両方同じ処理で扱える。

```json
POST /users/2/follow
X-User-Id: 1

// 新規 → 201
{ "follower_id": 1, "followee_id": 2, "following": true }

// 既存 → 200
{ "follower_id": 1, "followee_id": 2, "following": true }
```

---

## プライバシー制御（is_public）

アクティビティは `is_public` フラグで公開・非公開を制御する。

| 閲覧者 | 公開 | 非公開 |
|---|---|---|
| 本人 | ○ | ○ |
| 他ユーザー | ○ | × |

フィード（`GET /feed`）は常に `is_public = 1` のみ返す。
`GET /users/{userId}/activities` は本人なら全件、他人なら公開のみ。

---

## セキュリティ設計

### actor_id はヘッダーから取得

```php
// 正しい: X-User-Id ヘッダーから取得
$actorId = (int) ($request->getHeaderLine('X-User-Id') ?: 0);

// 危険: リクエストボディから取得しない
// $actorId = (int) $body['actor_id'];  // ← 絶対 NG
```

リクエストボディに `actor_id` が含まれていても無視する。
認証ミドルウェアが設定した `X-User-Id` ヘッダーを唯一の信頼源とする。

### 自分以外のアクティビティ投稿を防止

```php
$userId = (int) $this->routeParam($request, 'userId');
if ($userId !== $actorId) {
    return $this->json->create(['error' => 'forbidden'], 403);
}
```

### 自己フォロー防止

```php
if ($followerId === $followeeId) {
    return $this->json->create(['error' => 'cannot follow yourself'], 422);
}
```

---

## アクティビティタイプ

```php
private const array VALID_TYPES = ['post', 'like', 'comment', 'follow', 'share'];
```

不正な type は ValidationException → 422 で拒否される。

```json
POST /users/1/activities
{
    "type": "like",
    "summary": "Liked article about PHP 8.4",
    "object_id": 123,
    "object_type": "article",
    "is_public": true
}
```

---

## テスト構成

```
tests/
  Feed/
    FeedTest.php      # 機能テスト (40 tests, SQLite)
    VulnTest.php      # 脆弱性診断 (12 tests, SQLite)
    MysqlFeedTest.php # MySQL 統合テスト (5 tests)
```

MySQL テストの実行:

```bash
docker run --rm --network nene2-ft_default \
  -v /path/to/feedlog:/app -w /app \
  -e MYSQL_HOST=mysql -e MYSQL_DB=ft_test \
  -e MYSQL_USER=ft_user -e MYSQL_PASSWORD=ft_pass \
  nene2-app php vendor/bin/phpunit tests/Feed/MysqlFeedTest.php
```

---

## 実装サンプル（FT153）

`/home/xi/docker/NENE2-FT/feedlog/`
