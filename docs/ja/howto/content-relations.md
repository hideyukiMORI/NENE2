# コンテンツリレーション — 型付き M:N 自己参照リンク

**リレーション型カラム**を持つ結合テーブルを使って、記事（または任意のリソース）を相互にリンクします。非対称型（sequel ↔ prequel）には自動逆挿入をサポートし、対称型（related、reference）にも同じ逆挿入ロジックを適用します。

**参照実装:** `FT173 relatedlog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## このパターンを使うべき場面

| 使うべき場合 | 代替を検討する場合 |
|---|---|
| リソース間が型付きエッジでリンクされる | 型なしの「関連」リンクだけが必要 |
| 非対称エッジが必要（A は B の続編） | 単純なタグシステムで十分 |
| 双方向クエリを高速に保ちたい | 多段グラフトラバーサルが必要 |
| リレーション型が UI の動作を決める（「続編を見る」等） | — |

---

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- DB レベルで自己参照を防止
);
```

### 設計メモ

- `UNIQUE (article_id, related_id, relation_type)` 制約により、同じ型の重複エッジが防止されます。同じペアでも**複数**の型を持てます（例: A → B を `related` かつ `reference` として）。
- `CHECK (article_id != related_id)` はデータベースレベルで自己ループを防止します。
- **両方向を保存**: `A → B (sequel)` を追加すると `B → A (prequel)` も挿入されます。これにより、JOIN なしでも記事ごとのクエリが簡単になります（`WHERE article_id = ?`）。

---

## リレーション型

```php
enum RelationType: string
{
    case Related   = 'related';    // 対称: A related B ↔ B related A
    case Sequel    = 'sequel';     // 非対称: A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // 非対称: sequel の逆
    case Reference = 'reference';  // 対称: 双方向引用

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related, reference は自己逆
        };
    }
}
```

---

## コア操作: 自動逆挿入によるリレーション追加

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. 両方の記事が存在することを確認
    // 2. 重複チェック（UNIQUE 制約でもキャッチできる）
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. 順方向リレーションを挿入
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. 逆方向を挿入（まだ存在しない場合）
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### リレーション削除（逆方向もカスケード）

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // 逆方向を削除
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## エンドポイント

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/articles` | 記事を作成する |
| `GET` | `/articles/{id}` | 関連スタブを埋め込んで記事を取得する |
| `POST` | `/articles/{id}/relations` | リレーションを追加する（+ 逆方向を自動挿入） |
| `GET` | `/articles/{id}/relations` | リレーション一覧（`?type=sequel` でフィルター） |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | リレーションを削除する（`?type=sequel` 必須） |

---

## レスポンス形状

### GET /articles/{id} — 関連を埋め込んで取得

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations — リクエスト

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

`type` クエリパラメーターは**必須**です — 同じペアは複数のリレーション型を同時に持てるため、型によってどのエッジを削除するかを特定します。

---

## ドメイン層の構造

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # inverse() を持つ enum
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## エッジケース

| シナリオ | 動作 |
|---|---|
| 自己参照（`article_id == related_id`） | 422 — DB の前にハンドラーでチェック |
| 同じペア間の型の重複 | 409 Conflict |
| 同じペアで異なる型 | 201 — 有効。別行として保存 |
| 存在しないリレーションの削除 | 404 |
| `type` パラメーターなしで削除 | 422 |
| 存在しない記事 | 各無効 ID に対して 404 |

---

## 関連ハウツー

- [タグシステム（M:N）](./tagging-system.md) — 型なしエッジのリソース対タグ M:N
- [スレッドコメント](./threaded-comments.md) — 自己参照 `parent_id`
- [階層データ](./hierarchical-data.md) — マテリアライズドパスツリー
- [ユーザーフォローシステム](./user-follow-system.md) — ユーザー間の有向 M:N
