# ハウツー: ライブポールシステム

## 概要

このガイドでは NENE2 を使って、管理者ゲートのポール作成、ユーザーごとの投票重複排除、ポールライフサイクル管理、結果集計を備えたライブポールシステム API の構築方法を解説します。

**参照実装**: `../NENE2-FT/polllog/`

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

主要な制約:
- `UNIQUE (poll_id, user_id)` — ユーザーがポールごとに 1 回以上投票することを防止します。
- `ON DELETE CASCADE` — ポール削除時にオプションと投票を削除します。

---

## ルートテーブル

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/polls` | 管理者 | オプション付きでポールを作成する |
| `GET` | `/polls` | なし | すべてのポールを一覧表示する |
| `GET` | `/polls/{id}` | なし | 投票数付きでポールを取得する |
| `POST` | `/polls/{id}/vote` | ユーザー | 投票する |
| `POST` | `/polls/{id}/close` | 管理者 | ポールを終了する |

---

## 管理者認証パターン

共有シークレットを `X-Admin-Key` ヘッダーで渡してください。フェイルクローズロジックを使ってください:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // フェイルクローズ: キーが設定されていない → 管理者なし
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

管理者でない場合に `403 Forbidden` を返してください:
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## オプション付きのポール作成

最低 2 つのオプションをバリデーションし、トランザクションで挿入してください:

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## 重複排除付きの投票

UNIQUE 制約違反をキャッチして重複投票を検出してください:

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // オプションがこのポールに属することを確認する
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## 投票数の集計

ゼロ票のオプションを含めるために `LEFT JOIN` を使ってください:

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## HTTP ステータスコード

| 状況 | ステータス |
|------|---------|
| ポール作成 | 201 |
| 投票完了 | 201 |
| ポール取得 / 終了 | 200 |
| ポールが見つからない | 404 |
| 無効なオプション ID | 422 |
| 質問が欠落またはオプションが 2 件未満 | 422 |
| 非整数の option_id | 422 |
| 既に投票済み | 409 |
| 終了したポールへの投票 | 409 |
| 管理者キーなし | 403 |
| X-User-Id ヘッダーなし | 400 |

---

## バリデーションチェックリスト

- `question`: 空でない文字列
- `options`: 2 件以上の空でない文字列の配列
- `option_id`: `is_int()` でなければならない（`'1'` のような文字列を拒否）
- `X-User-Id`: `ctype_digit()` + 正の整数
- 投票または終了前にポールが存在しなければならない
- オプションはターゲットポールに属していなければならない（クロスポールインジェクション）

---

## セキュリティノート

- **管理者キーのフェイルクローズ**: 空のキーは誰も管理者でないことを意味します。
- **`hash_equals()` を使用**: 管理者キー比較でのタイミング攻撃を防止します。
- **UNIQUE 制約**は権威ある重複投票ガードです — アプリケーションレベルのチェックだけでは並行負荷下では不十分です。
- **オプション所有権チェック**は異なるポールのオプションで投票されることを防止します。
