# NENE2 でコンテンツ Draft ライフサイクル（Draft → Published → Archived）を構築する

このガイドでは、draft / publish / archive のステートマシンを持つ記事管理システムを構築する方法を説明します。ステート遷移は著者のみが行え、公開された記事のみが読者に見える構成です。

**Field Trial**: FT142  
**NENE2 バージョン**: ^1.5  
**カバーするトピック**: enum によるステートマシン、遷移ガード、著者所有権チェック、ステータスフィルタ付き公開リスト、同秒ソート安定性

---

## 何を作るか

- `POST /articles` — 記事を作成（常に `draft` で開始）
- `GET /articles` — 公開済み記事のみを一覧
- `GET /articles/{id}` — 記事を取得（著者は任意のステータスを見られる。他者は `published` のみ）
- `PUT /articles/{id}` — 記事を編集（draft のみ、著者のみ）
- `POST /articles/{id}/publish` — 遷移 `draft → published`（著者のみ）
- `POST /articles/{id}/archive` — 遷移 `published → archived`（著者のみ）

---

## データベーススキーマ

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`published_at` と `archived_at` は nullable で、対応する遷移時のみ設定されます。

---

## 遷移ガードを持つ ArticleStatus enum

```php
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canPublish(): bool
    {
        return $this === self::Draft;
    }

    public function canArchive(): bool
    {
        return $this === self::Published;
    }
}
```

ハンドラーは現在のステータスを読み取り、ガードメソッドを呼び、遷移が不正な場合は 422 を返します。

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

有効な遷移:
- `draft → published`（publish 経由）
- `published → archived`（archive 経由）
- draft への戻り遷移はなし。

---

## 著者の可視性 — draft は他者から隠す

非著者は draft を読めません。記事が存在することを漏らさないために、403 ではなく 404 を返します。

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

403 を返すと記事の存在が確定してしまいます。まだ公開されていないコンテンツに対しては 404 が正しい選択です。

---

## 同秒ソートの安定性

複数の記事が同じ秒内に公開された場合、`ORDER BY published_at DESC` だけでは順序が非決定的になります。`id DESC` を tiebreaker として追加します。

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

`id` が大きいほど後に作成されたことを意味するため、同秒内では実質的に挿入順でソートされます。

---

## よくある落とし穴

| 落とし穴 | 対処 |
|---------|-----|
| 非著者の draft 読み取りに 403 を返す | 404 を返す — コンテンツ存在の漏洩を防ぐ |
| `published → draft` の再オープンを許可する | `canEdit()` は `Draft` 以外で false を返す。"unpublish" エンドポイントは置かない |
| すでに公開済みの記事を再度 publish する | `canPublish()` は `Published` に対して false → 422 |
| draft を archive する | `canArchive()` は `Published` 以外で false → 422 |
| 同タイムスタンプでのリスト順が非決定的 | `id DESC` を二次ソートに追加 |
