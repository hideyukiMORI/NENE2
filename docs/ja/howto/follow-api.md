# ハウツー: フォロー / アンフォロー API

> **FT リファレンス**: FT314 (`NENE2-FT/followlog`) — ソーシャルフォローグラフ: 冪等なフォロー（最初は POST 201、繰り返しは 200）、自己フォロー防止（422）、アンフォロー（DELETE 204）、stats によるフォロワー/フォロー数、最新順のページネーションリスト、フォロー確認、相互フォローサポート、20 テスト / 72 アサーション PASS。

このガイドでは、ユーザーが互いにフォロー・アンフォローでき、フォロワー/フォロー数とリストエンドポイントを備えたソーシャルフォローシステムの構築方法を示します。

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

`UNIQUE (follower_id, followee_id)` 制約が DB レベルでフォロー関係の冪等性を強制します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/users` | ユーザーを作成する |
| `POST` | `/users/{id}/follow` | 別のユーザーをフォローする |
| `DELETE` | `/users/{id}/follow/{followeeId}` | アンフォローする |
| `GET` | `/users/{id}/stats` | フォロワー/フォロー数を取得する |
| `GET` | `/users/{id}/followers` | フォロワーを一覧表示する（最新順） |
| `GET` | `/users/{id}/following` | フォロー中を一覧表示する（最新順） |
| `GET` | `/users/{id}/is-following/{targetId}` | フォロー確認 |

## 冪等なフォロー

```php
// 最初のフォロー → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// 同じペアで繰り返しフォロー → 200 OK（201 でも 409 でもない）
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// ハンドラーロジック
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // すでにフォロー済み
}
```

## 自己フォロー防止

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## アンフォロー

```php
DELETE /users/1/follow/2
→ 204 No Content   // アンフォロー成功

DELETE /users/1/follow/2  // フォローしていない場合
→ 404 Not Found
```

アンフォロー後に再フォローするサイクルは正しく動作します: DELETE → POST で再び 201 が返ります。

## Stats

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = このユーザーをフォローしているユーザー数。
`following_count` = このユーザーがフォローしているユーザー数。

存在しないユーザー → 404。

## フォロワー / フォロー中リスト

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- `follows.id DESC` 順（最新のフォロワーが先頭）。
- `GET /users/{id}/following` も同じ構造。
- 存在しないユーザー → 404。

## フォロー確認

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 が 2 をフォロー中

GET /users/1/is-following/2  // アンフォロー後
→ 200  {"following": false}
```

フォローしていない場合は 404 ではなく `false` を返します — 確認自体は常に有効です。

## 相互フォロー

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

相互フォローは 2 つの独立したフォロー行に過ぎません — 特別なテーブルやロジックは不要です。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 重複フォローに 409 を返す | クライアントのリトライロジックが壊れる。冪等な操作はエラーではなく 200 を返すべき |
| 自己フォローを許可する | stats が壊れる（`followers_count` が自己カウントで水増し）。フィードが不正になる |
| (follower_id, followee_id) に UNIQUE 制約なし | 並行したフォロークリックで重複行が作成される競合状態が発生する |
| 存在しないフォローへの DELETE で 204 を返す | クライアントが「アンフォロー済み」と「一度もフォローしていない」を区別できない。404 を使うべき |
| 最新順でなく名前や ID 順でソートする | 長いリストで最新のフォロワー/フォロー中が埋もれる。UX の期待は「最近誰がフォローしたか」 |
| ユーザー間でフォロー数を共有する | 無関係なユーザー間でフォロワー数が混入する。常に user_id でスコープすること |
