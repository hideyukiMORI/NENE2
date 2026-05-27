# ハウツー: ゲームスコア & リーダーボード API

> **FT リファレンス**: FT259 (`NENE2-FT/scorelog`) — ゲームスコア送信（一括挿入最大 100 件、全件成功か全件失敗）、`best_score` 集計と `play_count` を使ったプレイヤーごとのリーダーボード、負のスコア防止、ページネーション、20 テスト PASS。

このガイドでは、ゲームスコアシステムの構築方法を示します。個別のプレイスコアを記録し、結果を一括インポートし、ゲームごとにランク付きリーダーボードを計算します。

## スキーマ

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- ISO 8601 date: YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` が DB レベルで負のスコアを防止します。`game` と `player` のインデックスがフィルター付きリストとリーダーボードクエリをサポートします。

## エンドポイント

| メソッド | パス                | 説明                                  |
|--------|---------------------|--------------------------------------|
| `POST` | `/scores`           | スコアを 1 件送信する                 |
| `POST` | `/scores/bulk`      | 最大 100 件を一括送信する             |
| `GET`  | `/scores`           | スコアを一覧表示する（フィルター + ページネーション） |
| `GET`  | `/scores/leaderboard` | ゲームごとのプレイヤーリーダーボード |
| `GET`  | `/scores/{id}`      | スコアを 1 件取得する                |
| `DELETE` | `/scores/{id}`    | スコアを削除する                     |

## スコア送信

```php
POST /scores
{
  "player":    "Alice",
  "game":      "tetris",
  "score":     1500,
  "played_at": "2026-01-15"
}

→ 201
{
  "id": 1,
  "player": "Alice",
  "game": "tetris",
  "score": 1500,
  "played_at": "2026-01-15",
  "created_at": "..."
}
```

1 人のプレイヤーが同じゲームに複数回スコアを記録できます — 各プレイが別個のレコードになります。

### バリデーション

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player は必須

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // score は >= 0 でなければならない

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at は YYYY-MM-DD 形式でなければならない

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // score = 0 は有効
```

## スコア一覧

```php
// すべてのスコア
GET /scores
→ 200  {"items": [...], "total": 10}

// ゲームでフィルター
GET /scores?game=tetris
→ 200  {"items": [/* tetris のスコアのみ */], "total": 3}

// プレイヤーでフィルター
GET /scores?player=Alice
→ 200  {"items": [/* Alice のスコアのみ */], "total": 2}

// ページネーション
GET /scores?limit=2&offset=1
→ 200  {"items": [/* インデックス 1 から 2 件 */], "total": 5}
```

## 一括送信

```php
POST /scores/bulk
{
  "scores": [
    {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
    {"player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16"},
    {"player": "Carol", "game": "snake",  "score": 500,  "played_at": "2026-01-15"}
  ]
}

→ 201
{
  "created": 3,
  "scores": [
    {"id": 1, "player": "Alice", ...},
    {"id": 2, "player": "Bob",   ...},
    {"id": 3, "player": "Carol", ...}
  ]
}
```

### 一括バリデーションルール

```php
// 空の配列
POST /scores/bulk  {"scores": []}
→ 422  // 最低 1 件必要

// 無効なエントリが 1 件でもあればバッチ全体が失敗する
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" を空にできない — レコードは 1 件も挿入されない

// 100 件超
POST /scores/bulk  {"scores": [...101 entries...]}
→ 422  // 一括リクエストは最大 100 件
```

**全件成功か全件失敗**: 挿入前にすべてのエントリをバリデーションしてください。DB トランザクションを使ってアトミック性を確保してください。

### 一括実装

```php
public function bulkSubmit(array $entries): array
{
    // 最初にすべてのエントリをバリデーション
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // トランザクション内ですべて挿入
    $this->db->beginTransaction();
    try {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $this->repo->insert($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
        }
        $this->db->commit();
        return $this->repo->findByIds($ids);
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

## リーダーボード

```php
GET /scores/leaderboard?game=tetris

→ 200
{
  "game":    "tetris",
  "top":     10,
  "entries": [
    {"rank": 1, "player": "Alice", "best_score": 3000, "play_count": 2},
    {"rank": 2, "player": "Bob",   "best_score": 2000, "play_count": 1},
    {"rank": 3, "player": "Carol", "best_score": 500,  "play_count": 1}
  ]
}
```

各プレイヤーは**1 回**だけ登場し、全プレイ中の**最高**スコアと `play_count` が表示されます。

### Top-N 制限

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3 players...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top は >= 1 でなければならない

GET /scores/leaderboard          // game なし
→ 422
```

### リーダーボード SQL

```sql
SELECT
    player,
    MAX(score)   AS best_score,
    COUNT(*)     AS play_count,
    RANK() OVER (ORDER BY MAX(score) DESC) AS rank
FROM scores
WHERE game = ?
GROUP BY player
ORDER BY best_score DESC
LIMIT ?
```

`RANK() OVER (ORDER BY MAX(score) DESC)` は同スコアのプレイヤーに同じランクを付けます（後続のランクにギャップができます）。ギャップなしにしたい場合は `DENSE_RANK()` を使用してください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| アプリケーション層のみで負のスコアを許可する | `CHECK(score >= 0)` DB 制約が最終ガード。アプリケーションバリデーションはバイパスできる |
| トランザクションなしで一括エントリを 1 件ずつ挿入する | 途中の失敗でバッチが半分だけ DB に残る。コミット済みと未コミットの区別が不可能 |
| 挿入ループ内で一括エントリをバリデーションする | バリデーション失敗前に最初の N 件が挿入される。DB に不完全なデータが残る |
| GROUP BY なしで `score = MAX(score)` を使う | プレイヤーグループなしでテーブル全体を集計する。誤ったリーダーボード結果になる |
| LIMIT なしでリーダーボードに全プレイヤーを返す | 上限なしの全テーブルスキャンとソート。大きなスコアテーブルでの DoS リスク |
| PHP ですべてのスコアを取得して `best_score` を計算する | プレイヤーあたり O(N)。SQL の `MAX()` 集計を使うべき |
