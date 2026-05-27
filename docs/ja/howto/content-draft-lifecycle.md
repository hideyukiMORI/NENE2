# ハウツー: NENE2 でコンテンツドラフトライフサイクルを構築する（Draft → Published → Archived）

このガイドでは、ドラフト/公開/アーカイブのステートマシンを持つ記事管理システムの構築を説明します。著者のみが状態を遷移でき、公開された記事のみが読者に表示されます。

**フィールドトライアル**: FT142  
**NENE2 バージョン**: ^1.5  
**カバートピック**: enum を使ったステートマシン、遷移ガード、著者所有権チェック、ステータスフィルタリングされたパブリック一覧、同一秒のソート安定性

---

## 構築するもの

- `POST /articles` — 記事を作成する（常に `draft` として開始）
- `GET /articles` — 公開された記事のみを一覧表示する
- `GET /articles/{id}` — 記事を取得する（著者は任意のステータスを見られる。他者は `published` のみ）
- `PUT /articles/{id}` — 記事を編集する（ドラフトのみ、著者のみ）
- `POST /articles/{id}/publish` — `draft → published` に遷移する（著者のみ）
- `POST /articles/{id}/archive` — `published → archived` に遷移する（著者のみ）

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

`published_at` と `archived_at` は nullable です — 対応する遷移時のみ設定されます。

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

ハンドラーは現在のステータスを読み取り、ガードメソッドを呼び出し、遷移が無効な場合は 422 を返します:

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

有効な遷移:
- `draft → published`（publish 経由）
- `published → archived`（archive 経由）
- ドラフトに戻る遷移はありません。

---

## 著者の可視性 — 他者からのドラフト非表示

非著者はドラフトを読めません。記事が存在することが漏れないように 404 を返します（403 ではない）:

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

403 を返すと記事が存在することが確認されます。まだ公開されていないコンテンツには 404 が正しい選択です。

---

## 同一秒のソート安定性

複数の記事が同じ秒に公開された場合、`ORDER BY published_at DESC` だけでは非決定論的な順序になります。タイブレーカーとして `id DESC` を追加します:

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

`id` が高いほど後から作成されたことを意味するため、これにより同じ秒内の挿入順でソートされます。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| 非著者のドラフト読み取りに 403 を返す | 404 を返す — コンテンツ存在の漏洩を防止する |
| `published → draft` への再オープンを許可する | `canEdit()` は `Draft` でない限り false を返す。「公開取り消し」エンドポイントなし |
| すでに公開された記事を公開する | `canPublish()` は `Published` に対して false を返す → 422 |
| ドラフトをアーカイブする | `canArchive()` は `Published` でない限り false を返す → 422 |
| 同じタイムスタンプでの非決定論的な一覧順序 | セカンダリソートとして `id DESC` を追加する |
