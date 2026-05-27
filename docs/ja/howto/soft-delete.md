# ソフトデリート（論理削除）

ソフトデリートはレコードをデータベースに保持しますが、`deleted_at` タイムスタンプを設定することで削除済みとしてマークします。これにより以下が可能になります:
- 取り消し / リストア機能
- 監査証跡（誰が何をいつ削除したか）
- 参照整合性（パージされるまでレコードは参照可能）

## スキーマ

アクティブなレコードには `NULL`、削除されたレコードにはタイムスタンプを持つ `deleted_at` カラムを追加してください:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = アクティブ、タイムスタンプ = 削除済み
);
```

## 重大なルール: 常に deleted_at をフィルタリングする

**アクティブなレコードのみを返すべきすべてのクエリは `AND deleted_at IS NULL` を含まなければなりません。** このフィルターを見落とすのが最も一般的な間違いです — コードは動作しますが削除されたデータが API レスポンスに漏洩します。

```php
// ❌ フィルターなし — 削除されたレコードも返す
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ 削除済みを除外
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

これはすべてのクエリに適用されます: `findById`、`findAll`、`findByUser`、ページネーションクエリ、および JOIN ターゲット。

## エンティティ

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## リポジトリパターン

`$includeTrashed = false` フラグを使用してください。デフォルトの `false` は呼び出し元が削除されたレコードを見るために明示的にオプトインする必要があることを意味し、誤った漏洩を防ぎます:

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // アクティブのみ
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // 見つからない、またはゴミ箱にない
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** 完全削除 — ゴミ箱からのみ許可。 */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // ガード: まずゴミ箱にある必要がある
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### INSERT には `insert()` を使う

レコードを作成する際は `insert()` を使用してください（`execute()` + `lastInsertId()` ではなく）:

```php
// ❌ 2 回の呼び出し
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ 1 回の呼び出し — 挿入された行 ID を返す
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## エンドポイント

典型的なソフトデリート API:

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/articles` | 作成する |
| `GET` | `/articles` | アクティブなレコードのみ |
| `GET` | `/articles/trash` | 削除されたレコードのみ |
| `GET` | `/articles/{id}` | 1 件取得する（削除済みの場合は 404） |
| `DELETE` | `/articles/{id}` | ソフトデリート → すでに削除済みの場合は 404 |
| `POST` | `/articles/{id}/restore` | リストアする → ゴミ箱にない場合は 404 |
| `DELETE` | `/articles/{id}/purge` | ハードデリート → ゴミ箱にない場合は 404 |

**REST セマンティクスの注意:** `DELETE /articles/{id}` は完全な削除ではなくソフトデリートとして動作します。これがクライアントを驚かせる場合は、OpenAPI スペックで明確に文書化するか、ソフトデリートアクションには `POST /articles/{id}/trash` を使用してください。

## レスポンスには常に `deleted_at` を含める

クライアントが追加のリクエストなしにリソースの状態を判定できるように、すべてのレスポンスに `deleted_at` を含めてください:

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = アクティブ; タイムスタンプ = 削除済み
]);
```

## 外部キーとソフトデリート

他のテーブルがソフトデリートされたレコードを参照している場合:
- ソフトデリートは外部キー制約を壊しません — 行はまだ存在します
- ハードデリート（パージ）は参照している行が存在する場合に制約に違反する可能性があります
- パージする前に依存レコードを確認するか、依存関係にソフトデリートをカスケードしてください

## コードレビューチェックリスト

- [ ] アクティブなレコードのすべてのクエリに `AND deleted_at IS NULL` が含まれている
- [ ] `findById()` のデフォルトは `$includeTrashed = false` — 呼び出し元が明示的にオプトインする
- [ ] `purge()` はアクティブなレコードのハードデリートをガードする（`isDeleted()` チェック）
- [ ] `restore()` はレコードがゴミ箱にない場合に `null`（→ 404）を返す
- [ ] ソフトデリートされたテーブルの JOIN クエリも結合テーブルで `deleted_at IS NULL` をフィルタリングする
- [ ] クライアントが状態を判定できるように `deleted_at` が API レスポンスに含まれている
- [ ] `DELETE /articles/{id}` の動作（ソフト vs ハード）が OpenAPI で文書化されている
- [ ] テストが以下をカバーしている: 削除 → GET で 404、一覧が削除済みを除外、リストア → 再表示、パージ → どこからも消える、二重削除 → 404、アクティブをパージ → 404
- [ ] INSERT には `insert()` が使用されている（`execute()` + `lastInsertId()` ではなく）
