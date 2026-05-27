# ハウツー: ソフトデリート、ゴミ箱、完全パージ

> **FT リファレンス**: FT257 (`NENE2-FT/softdeletelog`) — `deleted_at` カラムによるソフトデリート / ゴミ箱 / 完全パージパターン

レコードの 3 ステージライフサイクルを実演します: アクティブ → ソフトデリート（ゴミ箱）→ 完全パージ。
アクティブリストは削除されたレコードを自動的に除外します。専用のゴミ箱エンドポイントは削除されたレコードのみを一覧表示します。
リストアはレコードをゴミ箱からアクティブに戻します。パージはレコードをデータベースから物理的に削除します
（ゴミ箱にある間のみ許可）。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/notes`               | ノートを作成する                                     |
| `GET`    | `/notes`               | アクティブなノートを一覧表示する（ソフトデリート除外） |
| `GET`    | `/notes/trash`         | ゴミ箱にあるノートのみを一覧表示する                 |
| `GET`    | `/notes/{id}`          | 単一のアクティブなノートを取得する                   |
| `DELETE` | `/notes/{id}`          | ノートをソフトデリートする（ゴミ箱に移動）           |
| `POST`   | `/notes/{id}/restore`  | ゴミ箱からアクティブにリストアする                   |
| `DELETE` | `/notes/{id}/purge`    | 完全削除する（ゴミ箱からのみ）                       |

> **ルート順序**: `/notes/trash` は `/notes/{id}` より前に登録しなければなりません。そうしないとリテラルセグメント `trash` がパスパラメーターとしてキャプチャされます。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` がソフトデリートマーカーです。`NULL` の場合はレコードがアクティブで、ISO タイムスタンプが設定されている場合はゴミ箱にあります。別の `is_deleted` ブーリアンは不要です — タイムスタンプは削除が_いつ_起こったかも記録し、監査証跡や TTL ベースのパージジョブに役立ちます。

---

## ドメインオブジェクト

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = アクティブ、非 null = ゴミ箱にある
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` はヌルチェックをカプセル化するため、呼び出し元は実装の詳細を知る必要がありません。

---

## リポジトリ: `includeTrashed` フラグ

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

デフォルト（`includeTrashed: false`）は `deleted_at IS NULL` フィルターを適用するため、呼び出し元は安全な動作を自動的に得ます。リストアとパージだけがゴミ箱のレコードを見る必要があり、明示的に `includeTrashed: true` を渡します。

**なぜ別の `findByIdIncludingTrashed()` メソッドではないのか?**

名前付きブーリアンパラメーターは呼び出し元で自己文書化されます:
- `findById($id)` — 明らかにアクティブのみ
- `findById($id, includeTrashed: true)` — 明らかにゴミ箱対応

別メソッドはハイドレーションロジックを複製するか、内部共有ヘルパーを必要とします。

---

## 一覧表示: アクティブ vs ゴミ箱

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

アクティブなノートは作成時間（新しい順）でソートされます。ゴミ箱のノートは削除時間（最近削除された順）でソートされます。これは「最近削除された」UI に自然です。

---

## ソフトデリート

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // アクティブのみのルックアップ
    if ($note === null) {
        return null;   // 見つからない、またはすでにゴミ箱にある → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

`includeTrashed` なしの `findById($id)` は、すでにゴミ箱にあるノートに `DELETE /notes/{id}` を呼び出すと `null` → 404 を返します。これにより二重削除の混乱を防ぎます: クライアントはノートがアクティブで存在しないのか、すでにゴミ箱にあるのかを 404 から区別できません。

---

## リストア

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // 見つからない、またはすでにアクティブ → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

`includeTrashed: true` がここで必要です — ノートは削除されているため、デフォルトフィルターがそれを隠します。
`!$note->isDeleted()` ガードはアクティブなノートを拒否します: アクティブなノートのリストアを呼び出すと `null` → 404 を返します。これにより「すでにリストア済み」のパスでリストアがべき等になります: 2 回リストアを呼び出すクライアントは最初の呼び出しで 200、2 回目で 404 を受け取ります。

---

## パージ（完全削除）

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // 見つからない、またはまだアクティブ → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` はゴミ箱のレコード（`isDeleted()` が true でなければならない）のみ機能します。アクティブなノートに `DELETE /notes/{id}/purge` を呼び出すと `false` → 404 を返します。これにより、誤ったエンドポイント経由でデータを誤って破壊することを防ぎます — クライアントはパージする前に明示的にソフトデリートする必要があります。

---

## ステートマシン

```
           POST /notes
               │
               ▼
           [active]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                  │
    DELETE /notes/{id}                                           │
               │                                                  │
               ▼                                                  │
           [trash]  ────────────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [gone — 物理 DELETE]
```

`active → trash` は可逆です。`trash → gone` は不可逆です。`active → gone` への直接パスはありません: パージには事前のソフトデリートステップが必要です。

---

## コントローラー: ルート登録順序

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← {id} より前でなければならない
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` は `/notes/{id}` より前に登録しなければなりません。順序が逆の場合、`GET /notes/trash` リクエストは `{id}` に `id = "trash"` としてマッチし、整数キャストに失敗して、ゴミ箱リストの代わりに 404 または空のボディを持つ 200 を返します。

---

## HTTP セマンティクス

| アクション | メソッド | 理由 |
|----------|--------|------|
| ソフトデリート | `DELETE` | クライアントはリソースをビューから削除したい |
| リストア | `POST` | べき等でない（2 回目の呼び出しは 404 を返す）; `POST` が適切 |
| パージ | `DELETE` | クライアントは完全な削除を意図する |

`PATCH /notes/{id}` と `{"deleted_at": null}` はリストアの代替ですが、`POST /restore` の方がより明示的で、内部カラム名を API 契約に漏洩させません。

---

## 設計比較

| アプローチ | アクティブフィルター | 削除マーカー | リストア | パージ |
|---|---|---|---|---|
| `deleted_at` タイムスタンプ | `WHERE deleted_at IS NULL` | タイムスタンプ + 監査証跡 | `SET deleted_at = NULL` | 物理 `DELETE` |
| `is_deleted` ブーリアン | `WHERE is_deleted = 0` | ブーリアンのみ | `SET is_deleted = 0` | 物理 `DELETE` |
| 別の `deleted_notes` テーブル | フィルター不要 | 行を別テーブルに移動 | 行を戻す | `deleted_notes` から削除 |

`deleted_at` は最も一般的なパターンです: 1 カラム、最小限のスキーマ変更、追加コストなしの組み込み監査タイムスタンプ。

---

## 関連 howto

- [`article-versioning-api.md`](article-versioning-api.md) — コンテンツのバージョン履歴（監査証跡パターン）
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — フィールドインジェクションを防ぐ明示的な DTO ホワイトリスティング
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — アトミックなマルチ書き込み操作
