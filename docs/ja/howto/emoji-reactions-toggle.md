# ハウツー: トグルとグループ化カウントを持つ絵文字リアクション

> **FT リファレンス**: FT263 (`NENE2-FT/reactionlog`) — 絵文字リアクション: トグル（追加/削除）、グループ化カウント、ユーザーごとのリアクション一覧

ユーザーが任意のターゲット（投稿、コメントなど）に任意の絵文字やリアクションタイプでリアクションできるリアクション API を実演します。単一の `PUT` エンドポイントがリアクションをトグルします: 未存在なら追加し、既に存在すれば削除します。リアクションタイプごとのグループ化カウントがサマリークエリで返されます。複合 `UNIQUE` 制約がユーザーごとにタイプ 1 つのリアクションを強制し、`DatabaseConstraintException` が並行トグル競合を処理します。

---

## ルート

| メソッド | パス | 説明 |
|----------|----------------------------------------------------|------------------------------------------|
| `PUT`    | `/reactions/{targetType}/{targetId}`               | リアクションをトグル（追加または削除）   |
| `DELETE` | `/reactions/{targetType}/{targetId}/{reactionType}`| 特定のリアクションを明示的に削除         |
| `GET`    | `/reactions/{targetType}/{targetId}`               | リアクションサマリーを取得（グループ化カウント） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` は一意な（ターゲット、ユーザー、リアクション）の組み合わせごとに 1 レコードを強制します。重複の挿入はコンストレイント違反を発生させ、アプリケーションは `DatabaseConstraintException` としてキャッチします。

`target_type` により同じリアクションシステムが別々のテーブルなしに複数のエンティティタイプ（`post`、`comment`、`message`）を扱えます。

---

## トグルパターン

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // リアクションが削除された
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // 競合状態: 同じユーザーからの並行トグル — 削除として扱う
        return false;
    }

    return true;   // リアクションが追加された
}
```

**フロー**:
1. `SELECT` でリアクションが存在するか確認する。
2. 見つかった場合: `DELETE` → `false` を返す（削除された）。
3. 見つからない場合: `INSERT` → `true` を返す（追加された）。
4. `INSERT` が UNIQUE 違反（`DatabaseConstraintException`）で失敗した場合: `SELECT` と `INSERT` の間に並行リクエストが同じ行を挿入した。「削除された」として扱う（並行トグルが優先）→ `false` を返す。

**なぜ `SELECT` してから `INSERT` するか?** 代替案は `INSERT OR IGNORE` を使い、`changes() == 0` を確認して行が既に存在していたケースを検出する方法です。明示的な `SELECT` アプローチは意図をより明確にし、後続クエリなしでクリーンな戻り値（追加か削除か）を生成します。

---

## コントローラー: 追加時 201、削除時 200

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

リアクション追加時は `201 Created`、削除時は `200 OK`。レスポンスボディの `added` フィールドにより、クライアントはステータスコードを確認せずに 2 つのケースを区別できます。

**トグルに `PUT` を使う理由?** `PUT` は HTTP セマンティクスで冪等です。単一ユーザーのトグルは効果として冪等です（2 回の同一 `PUT` は元の状態に戻る）。あるいは非冪等トグルには `POST` も許容されます。選択はチームの規約によります。

---

## グループ化カウントサマリー

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

2 つのクエリ:
1. グループ化カウント: `GROUP BY reaction_type ORDER BY cnt DESC` — 最も人気のものを先に。
2. ユーザーごとのリアクション（`$userId` が提供されている場合）: このユーザーが適用したリアクションタイプ。

`ORDER BY cnt DESC` は最もよく使われるリアクションを先に並べ、典型的な表示優先度に一致します。

---

## レスポンス例

**リクエスト**: `GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` はリアクションタイプからカウントへのマップです。`user_reactions` は `alice` が適用したリアクションのリストです。クライアントは `👍` をハイライトして alice のアクティブなリアクションを示せます。

---

## 明示的削除エンドポイント

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

ボディの `user_id` を持つ `DELETE /reactions/{targetType}/{targetId}/{reactionType}` は、トグルセマンティクスなしに特定のリアクションを削除します。現在の状態に関わらず特定のリアクションタイプを削除したいクライアントに便利です。

一致するリアクションが見つからない場合は 404 を返します（`$count == 0`）。

---

## 安全策としての複合 UNIQUE 制約

`UNIQUE(target_id, target_type, reaction_type, user_id)` 制約:
- **主な強制**: DB レベルで重複リアクションを防止する。
- **副次的な利点**: `SELECT` チェックをすり抜けた競合状態をキャッチする。
- **アプリケーションロジック**: `toggle()` は `DatabaseConstraintException` をキャッチし、削除として扱う。

制約なしでは、同じユーザーからの 2 つの並行する `PUT` リクエストが同一の行を 2 つ挿入してしまいます。制約 + 例外ハンドラーにより、並行性下でも不変条件（ユーザーごとにリアクションタイプ 1 行）が保たれます。

---

## 設計上の決定

| 決定 | 選択 | 根拠 |
|---|---|---|
| トグルエンドポイント | `PUT` | セマンティクス的に適切。冪等 |
| リアクション識別子 | 4 カラム複合キー | 別のリアクションタイプテーブル不要 |
| `target_type` | PATH パラメーター | 1 つのエンドポイントで複数のエンティティタイプを扱える |
| リクエストボディの `user_id` | 必須フィールド | この FT では認証ミドルウェアを不要にする |
| サマリーの `user_id` | クエリパラメーター | オプション — サマリーはパブリック。ユーザーごとの詳細はオプトイン |

---

## 関連ハウツー

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — タグの重複排除に INSERT OR IGNORE を使った M:N 結合テーブル
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — DB レベルの安全策としての複合ユニークキー
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — 複数の書き込みが一緒に成功または失敗する必要がある場合のアトミック操作
