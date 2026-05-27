# ハウツー: フィードバック収集 API

## 概要

ユーザーがターゲットエンティティに対してスコア（1〜5）とコメントを送信するフィードバックシステムです。管理者はすべてのフィードバックを一覧表示できます。パブリック統計エンドポイントで集計された平均値を表示します。

**リファレンス実装**: `../NENE2-FT/feedbacklog/`

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## ルート

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/feedback` | ユーザー | フィードバックを送信する |
| `GET` | `/feedback` | 管理者 | すべてのフィードバックを一覧表示する |
| `GET` | `/feedback/stats` | なし | 集計統計 |

## 重複防止

`UNIQUE (user_id, target)` は DB レベルでユーザーごとのターゲットに 1 件のフィードバックを強制します。アプリケーションレベルでも先にチェックします:

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## スコアバリデーション

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## 統計集計

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

カウントがゼロの場合は JSON で `NaN` を避けるために `null` の平均値を返します。

## HTTP ステータスコード

| 状況 | ステータス |
|-----------|--------|
| フィードバック送信済み | 201 |
| 統計 / 一覧 | 200 |
| X-User-Id なし | 400 |
| 空のターゲット / 不正なスコア | 422 |
| 管理者キーなし | 403 |
| 重複フィードバック | 409 |
