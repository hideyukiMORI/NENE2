# ハウツー: ドラフト → 公開 → アーカイブ ワークフロー

> **FT リファレンス**: FT305 (`NENE2-FT/draftlog`) — 記事ライフサイクルステートマシン: draft→published→archived の一方向遷移、著者のみの書き込みアクセス、非著者は公開済み記事のみ表示（ドラフトは 404 を返す）、公開済み記事は編集不可、公開一覧はドラフトとアーカイブを除外、20 テスト / 28 アサーション PASS。

このガイドでは、記事がドラフトとして開始し、公開されて表示され、アーカイブされてパブリック一覧から削除されるコンテンツライフサイクルの実装方法を示します。

## スキーマ

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

`CHECK (status IN (...))` により既知の状態のみが保存されます。`published_at` と `archived_at` のタイムスタンプが遷移の発生時刻を記録します。

## ステートマシン

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| 遷移 | 前提条件 | 違反時のエラー |
|---|---|---|
| draft → published | status は `'draft'` であること | 422 |
| published → archived | status は `'published'` であること | 422 |
| published → draft | ❌ 許可されない | — |
| archived → anything | ❌ 許可されない | — |

```php
// 公開ハンドラー
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// アーカイブハンドラー
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/articles` | `X-User-Id` | 記事を作成する（ドラフトとして開始） |
| `GET` | `/articles` | — | 公開済み記事のみを一覧表示する |
| `GET` | `/articles/{id}` | `X-User-Id` | 記事を取得する（可視性チェック） |
| `PUT` | `/articles/{id}` | `X-User-Id`（著者） | ドラフトを更新する（ドラフトのみ） |
| `POST` | `/articles/{id}/publish` | `X-User-Id`（著者） | 公開する |
| `POST` | `/articles/{id}/archive` | `X-User-Id`（著者） | アーカイブする |

## 新しい記事はドラフトとして開始

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

`status` は作成時にボディフィールドに関わらず常に `'draft'` です。クライアントは初期ステータスを選択できません。

## 可視性 — 非著者は公開済みのみ表示

```php
// 非著者は公開済み記事のみ表示できる
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

未公開の記事（ドラフトまたはアーカイブ）は非著者に 404 を返します。これにより以下を防ぎます:
- 他のユーザーが未公開ドラフトを読む
- 記事がアーカイブされたことを明かす

## 公開済み記事は編集不可

```php
// 更新ハンドラー — ドラフトのみ編集可能
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

公開されると、記事のコンテンツは固定されます。著者は編集するために非公開にする（このデザインではサポートされない）必要があります — このデザインでは、公開は一方向のゲートです。

## 一覧エンドポイント — 公開済みのみ

```php
// リポジトリ: SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

一覧エンドポイントは `status = 'published'` のみにフィルタリングします。ドラフトとアーカイブ済み記事はパブリック一覧に表示されません。

## 著者のみのアクション

すべての書き込み操作（更新、公開、アーカイブ）はアクターが記事の著者であることを確認します:

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 作成ボディでステータスを許可する | クライアントがレビューワークフローをバイパスして記事を `'published'` として開始できる |
| 非著者のドラフト GET に 403 を返す | 記事が存在することを明かす。未公開コンテンツを隠すために 404 を使用する |
| 公開済み記事の編集を許可する | ライブコンテンツを遡及的に変更する。読者の信頼を損なう |
| archived → published の遷移を許可する | アーカイブ済み記事が予期せず再表示される |
| パブリック一覧にドラフトを一覧表示する | 未公開コンテンツが準備できる前に公開される |
| `CHECK (status IN (...))` なし | 直接 DB への挿入で任意のステータス文字列が設定できる |
| アーカイブ済み記事が非著者に 200 を返す | コンテンツが存在してアーカイブされたことを非著者に伝える |
