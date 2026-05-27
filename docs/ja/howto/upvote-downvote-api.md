# ハウツー: アップボート / ダウンボート API

> **FT リファレンス**: FT347 (`NENE2-FT/votelog`) — トグルオフ（同じ方向に 2 回投票すると削除）、方向変更（up→down をアトミックに）、スコア集計（アップボート − ダウンボート）、UNIQUE(user_id, item_id) 制約を持つユーザーごとのアップボート/ダウンボート、15 テスト PASS。

このガイドでは、Reddit/Stack Overflow スタイルの投票システムの実装方法を説明します: 各ユーザーはアイテムごとに 1 票を投じることができ、同じ方向に再投票でトグルオフでき、または方向を変更できます。

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (item_id)  REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` はユーザーごとアイテムごとに 1 票を強制します。`CHECK(direction IN ('up', 'down'))` は DB レベルで他の値を拒否します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/items/{id}/vote`          | 投票を行う、トグル、または変更する |
| `GET`  | `/items/{id}/score`         | アイテムスコアを取得する          |
| `GET`  | `/items/{id}/vote/{userId}` | ユーザーの現在の投票を取得する    |

## 投票

```php
POST /items/1/vote
{"user_id": 42, "direction": "up"}

→ 200
{
  "vote": "up",
  "score": {
    "upvotes": 1,
    "downvotes": 0,
    "score": 1
  }
}
```

```php
POST /items/1/vote
{"user_id": 43, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 1, "downvotes": 1, "score": 0}}
```

## トグルオフ（同じ方向に 2 回）

**同じ方向**に 2 回目の投票をすると投票が削除されます:

```php
// 最初の投票
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// 同じ方向への 2 回目の投票 → トグルオフ
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` はユーザーがこのアイテムにアクティブな投票を持っていないことを意味します。

## 方向変更

**逆方向**に投票すると既存の投票がアトミックに反転します:

```php
// アップボートから始める
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// ダウンボートに変更
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## スコアの取得

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // アップボート − ダウンボート
}
```

投票がないアイテムのスコア:

```php
GET /items/1/score   // まだ投票がない
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## ユーザーの投票状態の取得

```php
// まだ投票していない
GET /items/1/vote/42
→ 200  {"vote": null}

// アップボート後
GET /items/1/vote/42
→ 200  {"vote": "up"}

// トグルオフ後
GET /items/1/vote/42
→ 200  {"vote": null}
```

## 実装

### 投票ハンドラーロジック

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // 同じ方向 → トグルオフ
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // 異なる方向 → インプレースで更新
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // 新規投票
        $this->repo->insertVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    }

    $score = $this->repo->getScore($itemId);

    return [
        'vote'  => $activeDirection,
        'score' => $score,
    ];
}
```

### スコア集計 SQL

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` は投票がない場合にゼロ値を保証します（空のセットの SUM は NULL を返す）。

### 投票 UPSERT パターン

```sql
-- 新規投票を挿入
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- 方向を更新（UNIQUE 制約が重複を防ぐ）
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- 削除（トグルオフ）
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## バリデーション

```php
// 無効な方向
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // direction は 'up' または 'down' でなければならない

// アイテムが存在しない
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## 複数ユーザー

```php
// 3 人のユーザーが同じアイテムに投票する
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(user_id, item_id)` がない | ユーザーが複数回投票してスコアを水増しできる |
| 方向変更に `INSERT OR REPLACE` を使用する | 新しい `id` と `created_at` が生成される; 投票履歴が失われる; 監査証跡が壊れる |
| トグルオフに 409 を返す | トグルオフは期待される動作であり、エラーではない; 新しい（null）投票状態を返すこと |
| すべての投票を取得してアプリケーションでスコアを計算する | リクエストごとに O(N); 1 つのクエリで SQL 集計を使用すること |
| ボディ経由で `direction: null` で投票を削除することを許可する | 曖昧; トグルパターン（同じ方向に 2 回）または別の DELETE エンドポイントを使用すること |
| スコア集計で `COALESCE` をスキップする | 行がマッチしない場合、`SUM()` は `NULL` を返す; `null − null` はクラッシュするか間違った型を返す |
