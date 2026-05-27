# NENE2 で絵文字リアクションシステムを構築する方法

このガイドでは、ユーザーが絵文字で投稿にリアクションし、グループ化されたカウントとユーザーごとのリアクション追跡を行うリアクションシステムを構築する手順を解説します。

**フィールドトライアル**: FT143  
**NENE2 バージョン**: ^1.5  
**対象トピック**: UNIQUE(post_id, user_id, emoji) 制約、GROUP BY 絵文字カウント、ユーザーごとのリアクション追跡、絵文字の長さバリデーション、MySQL 統合テスト

---

## 構築するもの

- `POST /posts` — 投稿を作成する
- `POST /posts/{id}/reactions` — リアクションを追加する（絵文字文字列、ユーザーごとに絵文字 1 つ）
- `DELETE /posts/{id}/reactions/{emoji}` — リアクションを削除する（自分のもののみ）
- `GET /posts/{id}/reactions` — リアクションカウントと現在のユーザーのリアクションを取得する

---

## データベーススキーマ

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (post_id, user_id, emoji)` — 1 人のユーザーが 1 つの投稿に 1 絵文字につき 1 行。同じユーザーが異なる絵文字でリアクションできます（👍 と ❤️ = 2 行）。複数のユーザーが同じ絵文字を使えます（それぞれが自分の行を持ちます）。

---

## 重複リアクション → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

`addReaction()` が `false` を返した場合、ハンドラーは 409 を返します。別途存在チェックは不要です。

---

## GROUP BY によるグループ化されたリアクションカウント

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

カウント降順（最も人気のある絵文字が先）でソートし、同数の場合はアルファベット順を使用します。結果は PHP の `array<string, int>` に直接マッピングされます:

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## ユーザーごとのリアクション（オプションのアクター）

`GET /reactions` エンドポイントはオプションの `X-User-Id` ヘッダーを受け付けます。存在する場合、レスポンスには呼び出し元が使用した絵文字のリストが含まれます:

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

これにより UI は現在のユーザーがすでにリアクションした絵文字を表示できます。

---

## 絵文字バリデーション

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` はバイトではなく Unicode コードポイントを数えます。🧑‍💻（テクノロジストの人）のような単一の絵文字は 3 コードポイントです。8 文字制限はほとんどの絵文字シーケンスに対応します。要件に合わせて調整してください。

---

## MySQL 統合テスト（FT143）

MySQL のティアダウン順序が重要です:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

MySQL スキーマは絵文字に `TEXT` ではなく `VARCHAR(32)` を使用します。これはプレフィックス長なしに UNIQUE キーでカラムを使えるようにするためです。`VARCHAR(32)` は最大 32 文字を格納でき、すべての絵文字シーケンスをカバーします。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| 重複した絵文字リアクションを許可する | `UNIQUE (post_id, user_id, emoji)` + `DatabaseConstraintException` をキャッチ |
| 絵文字の長さに `strlen()` を使用 | `mb_strlen()` を使用する — 絵文字はマルチバイト Unicode |
| 変更可能なカウントカラムが同期を失う | `reactions` テーブルから `GROUP BY emoji` でカウントする |
| MySQL 絵文字サポートが欠如 | `utf8mb4` 文字セットと絵文字カラムに `VARCHAR`（`CHAR` ではなく）を使用 |
| `fetchAll` 結果の `is_array()` チェックが常に true | チェックをスキップ。`fetchAll` はすでに `array<int, array<string, mixed>>` を返す |
