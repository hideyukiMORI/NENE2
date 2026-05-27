# ハウツー: ゲームリーダーボード & ランキング API

このガイドでは、プレイヤーがゲームごとにスコアを送信し、システムが個人ベストを追跡してランク付きリーダーボードを生成するリーダーボード API の構築方法を実証します。

## パターン概要

- プレイヤーは `POST /scores` でスコアを送信します（`X-User-Id` ヘッダーで識別）。
- 各送信は保存されます。個人ベスト（これまでの最高スコア）が返されます。
- `GET /leaderboard?game=<name>` は個人ベストでランク付けされた上位 N プレイヤーを返します。
- `GET /scores/{userId}?game=<name>` は特定プレイヤーの生のスコードをすべて一覧表示します。
- ゲームは完全に分離されています — "tetris" のスコアは "snake" に影響しません。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_scores_game ON scores (game, score DESC);
```

すべての試みが保存されます（アップサートなし）。これによりゲームごとのスコア履歴が可能になります。個人ベストはクエリ時に `MAX(score)` で導出されます。

## スコア送信

```php
public function submit(int $userId, string $game, int $score): void
{
    $this->pdo->prepare(
        'INSERT INTO scores (user_id, game, score, created_at) VALUES (:uid, :game, :score, :now)'
    )->execute([':uid' => $userId, ':game' => $game, ':score' => $score, ':now' => $this->now()]);
}

public function bestScore(int $userId, string $game): ?int
{
    $stmt = $this->pdo->prepare(
        'SELECT MAX(score) FROM scores WHERE user_id = :uid AND game = :game'
    );
    $stmt->execute([':uid' => $userId, ':game' => $game]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (int) $val : null;
}
```

ハンドラーは確認とともに個人ベストを返します:

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## リーダーボードクエリ

ユーザーごとのベストスコアを降順で、PHP でランクを追加します:

```php
public function leaderboard(string $game, int $limit): array
{
    $stmt = $this->pdo->prepare(
        'SELECT user_id, MAX(score) AS best_score
         FROM scores
         WHERE game = :game
         GROUP BY user_id
         ORDER BY best_score DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':game', $game, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

レスポンス:

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## バリデーションルール

| フィールド | ルール |
|---------|-------|
| `X-User-Id` ヘッダー | POST に必須; `ctype_digit`、1〜18 文字、値 > 0 |
| `game` ボディ/クエリ | 必須、空でない、最大 64 文字 |
| `score` ボディ | 整数 ≥ 0 のみ（`is_int($score) && $score >= 0`） |
| `userId` パスパラメーター | `ctype_digit`、最大 18 文字、値 > 0; そうでなければ 404 |
| `limit` クエリ | 1〜100、デフォルト 10; 無効な値はサイレントにクランプ |

文字列スコア（`"100"`）は値が数値でも `is_int()` が false を返すため 422 で拒否されます。

ゼロは有効なスコードです — スコアがつかないプレイヤーがいるゲームで便利です。

## ルート

```
POST   /scores              スコードを送信する（X-User-Id 必須）
GET    /leaderboard         上位 N 件のランク付きプレイヤー（?game= 必須、?limit= オプション）
GET    /scores/{userId}     プレイヤーの全スコード（?game= 必須）
```

## ゲーム分離

`game` カラムが名前空間として機能します。すべてのクエリで常に `WHERE game = :game` を含めてください。"tetris" でスコアを獲得したプレイヤーは "snake" リーダーボードには表示されません。

## 参照

- FT206 ソース: `../NENE2-FT/leaderboardlog/`
- 関連: `docs/howto/rate-limiting.md`（FT200）、`docs/howto/coupon-redemption.md`（FT204）
