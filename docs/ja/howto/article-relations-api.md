# ハウツー: 記事リレーション API

> **FT リファレンス**: FT334 (`NENE2-FT/relatedlog`) — 自動逆方向作成付きの型指定された記事間リレーション、対称型と非対称型のリレーション、型によるフィルター、GET レスポンスに埋め込まれたリレーションスタブ、17 テスト / 40 以上のアサーション PASS。

このガイドでは、コンテンツアイテム間の型指定されたリレーション（`related`、`sequel`、`prequel`、`reference`）を、すべてのリレーションが両方向で一貫して保たれるよう自動逆方向管理でモデル化する方法を説明します。

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
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` は同じ型の重複リレーションエッジを防止します。同じペア間の異なる型は許可されます。

## リレーション型と逆方向

| 提出された型 | 自動作成される逆方向 |
|---|---|
| `related` | `related`（対称） |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference`（対称） |

A→B が `sequel` の場合、サーバーはアトミックに B→A を `prequel` として挿入します。A→B を削除すると B→A も削除されます。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/articles` | 記事を作成する |
| `GET` | `/articles/{id}` | リレーション埋め込み付きで記事を取得する |
| `POST` | `/articles/{id}/relations` | リレーションを追加する |
| `GET` | `/articles/{id}/relations` | リレーションを一覧表示する（オプション ?type=） |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | リレーションを削除する（逆方向も削除） |

## 記事の作成

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// タイトルなし
POST /articles  {"body": "No title"}
→ 422

// ボディなし
POST /articles  {"title": "No body"}
→ 422
```

## リレーション埋め込み付きの GET 記事

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// リレーションなし
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## リレーションの追加

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// 逆方向が自動挿入: 記事 2 は記事 1 への "prequel" リレーションを持つ
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### 対称リレーション

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B も A への "related" リレーションを自動的に取得する
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### エラーケース

```php
// 未知の related_id
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// 重複（同じペア + 同じ型がすでに存在）
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// 自己リレーション
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// 無効なリレーション型
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### 同じペア間の複数型

同じペアに複数の異なるリレーション型を持てます:

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## リレーション一覧

```php
// すべてのリレーション
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// 型でフィルター
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// 未知の記事
GET /articles/9999/relations
→ 404
```

## リレーション削除

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// 逆方向も自動削除
GET /articles/2/relations
→ 200  {"data": []}  // 記事 1 への "related" はもうない

// 見つからない
DELETE /articles/1/relations/2?type=related
→ 404

// 型クエリパラメーターなし
DELETE /articles/1/relations/2
→ 422
```

## 実装 — アトミックな逆方向管理

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related、reference → 対称
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

両方の挿入/削除をトランザクションでラップします — 一方が失敗した場合、どちらもコミットされません。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 記事の存在確認なしでリレーションを挿入する | FK 違反またはサイレントな 0 行挿入。未知の ID には常に 404 を返す |
| 順方向 + 逆方向挿入をトランザクションで囲まない | 部分的な失敗で非対称データが残る（A→B は存在するが B→A はない） |
| `UNIQUE(article_id, related_id, relation_type)` なし | 重複エッジが一覧カウントを膨らませる |
| 自己リレーションを許可する | リレーショントラバーサルのサイクル。自分自身の `sequel` は意味がない |
| すべての型に対称を仮定をハードコードする | `sequel`→`sequel`（間違い）ではなく `prequel` |
| 順方向エッジのみを削除する | 逆方向のオーファンが残る。B は A が削除された後も A を prequel として「見る」 |
