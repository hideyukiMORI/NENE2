# ハウツー: 投票 / アンケート API

このガイドでは、NENE2 を使って重複投票防止機能付きの投票・アンケートシステムの構築方法を解説します。
**polllog** フィールドトライアル（FT217）で実証されたパターンです。

## 機能

- 2〜20 個の選択肢を持つ投票の作成（管理者のみ）
- 公開・非公開投票（非公開: 管理者のみアクセス可）
- 1 人のユーザーが 1 つの投票に 1 票のみ（UNIQUE 制約で強制）
- 選択肢ごとの投票数によるリアルタイム集計
- 全選択肢の合計投票数

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    label      TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    option_id  INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),  -- 1 ユーザー 1 投票
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_poll ON votes (poll_id, option_id);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/polls` | 管理者 | 選択肢付きで投票を作成する |
| `GET` | `/polls/{id}` | 公開 | 投票を取得する（非公開 → 非管理者には 404） |
| `POST` | `/polls/{id}/vote` | ユーザー | 投票する |
| `GET` | `/polls/{id}/results` | 公開 | 選択肢ごとの結果件数を取得する |

## 選択肢のバリデーション

```php
private const int MIN_OPTIONS   = 2;
private const int MAX_OPTIONS   = 20;
private const int MAX_LABEL_LEN = 100;

foreach ($rawOptions as $idx => $label) {
    if (!is_string($label) || trim($label) === '') {
        return $this->problem(422, 'validation-failed', "options[{$idx}] must not be empty.");
    }
    if (strlen($label) > self::MAX_LABEL_LEN) {
        return $this->problem(422, 'validation-failed', "options[{$idx}] too long (max 100).");
    }
}
```

## 重複投票防止

```php
/** @return 'ok'|'already_voted'|'invalid_option' */
public function vote(int $pollId, int $userId, int $optionId): string
{
    // 選択肢が投票に属することを確認（クロス投票の選択肢インジェクションを防ぐ）
    $stmt = $this->pdo->prepare(
        'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid'
    );
    $stmt->execute([':oid' => $optionId, ':pid' => $pollId]);
    if ($stmt->fetch() === false) {
        return 'invalid_option'; // → 422
    }

    // 既存の投票を確認
    $stmt2 = $this->pdo->prepare(
        'SELECT id FROM votes WHERE poll_id = :pid AND user_id = :uid'
    );
    if ($stmt2->fetch() !== false) {
        return 'already_voted'; // → 409
    }

    // INSERT — UNIQUE(poll_id, user_id) 制約がセーフティネット
    $this->pdo->prepare('INSERT INTO votes ...')->execute([...]);
    return 'ok';
}
```

## 結果の集計

`LEFT JOIN` を使うことで、投票数がゼロの選択肢も結果に表示されます:

```sql
SELECT o.id, o.label, o.sort_order,
       COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = :pid
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

```php
$results    = $this->repo->results($id);
$totalVotes = array_sum(array_column($results, 'votes'));

return $this->json([
    'poll_id'     => $id,
    'total_votes' => $totalVotes,
    'results'     => $results,
]);
```

## 非公開投票のアクセス制御

非公開投票は非管理者ユーザーに 404 を返します（存在を隠す）:

```php
// GET /polls/{id}
if (!(bool) $poll['is_public'] && !$this->isAdmin($req)) {
    return $this->problem(404, 'not-found', 'Poll not found.');
}
```

## セキュリティパターン

- **管理者フェイルクローズド**: `hash_equals()` の前に `if ($this->adminKey === '') return false;`
- **`is_int()`**: `option_id` の厳密な型チェック — 浮動小数点数や文字列を拒否します
- **`ctype_digit()`**: パス ID に対する ReDoS 安全な整数バリデーション
- **クロス投票の選択肢インジェクション**: `WHERE id = :oid AND poll_id = :pid` で異なる投票の選択肢の使用を防ぎます
- **`is_bool()`**: `is_public` フラグの厳密なチェック — `1`/`0`/`"true"` 等を拒否します
