# ハウツー: リーダーボード & スコアトラッキング API

このガイドでは NENE2 を使って、スコア送信、ベストパーユーザー集計による上位 N 件ランキング、個人スコア履歴を備えたリーダーボードシステムの構築方法を示します。
**leaderboardlog** フィールドトライアル（FT206）で実証されたパターンです。

## 機能

- ユーザーとゲームごとのスコア送信（`X-User-Id` ヘッダー）
- 上位 N 件リーダーボード: ユーザーごとのベストスコアを降順でランク付け（`MAX(score) GROUP BY user_id`）
- ユーザー/ゲームの任意の組み合わせに対する個人スコア履歴
- 個人ベストスコアクエリ
- 設定可能な制限（1〜100 にクランプ）

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
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/scores` | ユーザー | スコードを送信する |
| `GET` | `/leaderboard?game=<game>` | 公開 | 上位 N 件リーダーボード |
| `GET` | `/scores/{userId}?game=<game>` | 公開 | ユーザーのスコード履歴 |

## スコア送信

```php
$game  = trim((string) ($body['game'] ?? ''));
$score = $body['score'] ?? null;

if ($game === '' || strlen($game) > 64) {
    return $this->problem(422, 'validation-failed', 'game required (max 64 chars).');
}
if (!is_int($score) || $score < 0) {
    return $this->problem(422, 'validation-failed', 'score must be a non-negative integer.');
}
```

ポイント:
- `is_int($score)` — 厳格なチェック; JSON からの浮動小数点（`1.5`）と文字列を拒否
- ゲーム名は 64 文字に制限 — 過大なゲーム名 DoS を防止
- スコード非負 — 負のスコードインジェクションを防止

201 で更新された個人ベストを返します:

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## 上位 N 件ランキングクエリ

PHP でのデンスランク割り当てによるベストパーユーザーランキング:

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

    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

- `MAX(score) GROUP BY user_id` — ユーザーあたり 1 行、個人ベスト
- `ORDER BY best_score DESC` — 最高スコアが先頭
- `PDO::PARAM_INT` の LIMIT バインド — SQL インジェクションセーフ

レスポンス例:

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## 制限のクランプ

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

無効または範囲外の制限はサイレントにデフォルト 10 になります — LIMIT にはクライアント提供の整数を信頼しないでください。

## バリデーションパターン

| 入力 | チェック | 理由 |
|------|---------|------|
| `score` | `is_int($score) && $score >= 0` | 浮動小数点、文字列、負の値を拒否 |
| `game` | `strlen($game) <= 64` | 過大な入力を防止 |
| `limit` | `ctype_digit()` + 範囲クランプ | ReDoS セーフ、有界 |
| `userId`（パス） | `ctype_digit()` + `> 0` | DB クエリ前にバリデーション |
