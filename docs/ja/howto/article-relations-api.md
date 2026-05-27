# ハウツー: 記事リレーション API

> **FT リファレンス**: FT334 (`NENE2-FT/relatedlog`) — 自動逆方向作成付きの型付き記事間リレーション、対称型および非対称型のリレーション種別、種別によるフィルター、GET レスポンスへの埋め込みリレーションスタブ、17 テスト / 40+ アサーション PASS。

このガイドでは、コンテンツアイテム間の型付き関係 — `related`、`sequel`、`prequel`、`reference` — を自動逆方向管理でモデル化する方法を説明します。これにより、すべてのリレーションが両方向で一貫した状態を保ちます。

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

`UNIQUE(article_id, related_id, relation_type)` は同じ種別で重複するリレーションエッジを防止します。同じペア間での異なる種別は許可されます。

## リレーション種別と逆方向

| 提出された種別 | 自動作成される逆方向 |
|---|---|
| `related` | `related`（対称） |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference`（対称） |

A→B が `sequel` の場合、サーバーは B→A を `prequel` としてアトミックに挿入します。A→B を削除すると B→A も削除されます。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/articles` | 記事を作成する |
| `GET` | `/articles/{id}` | 埋め込みリレーション付きで記事を取得する |
| `POST` | `/articles/{id}/relations` | リレーションを追加する |
| `GET` | `/articles/{id}/relations` | リレーションを一覧表示する（オプション ?type=） |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | リレーション（とその逆方向）を削除する |

## 記事の作成

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// タイトルなし
POST /articles  {"body": "No title"}
→ 422

// 本文なし
POST /articles  {"title": "No body"}
→ 422
```

## 埋め込みリレーション付きの GET 記事

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

// リレーションまだなし
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

// 逆方向が自動挿入される: 記事 2 に 1 を指す "prequel" リレーションが付く
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### 対称リレーション

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B にも A への "related" リレーションが自動的に付く
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### エラーケース

```php
// 未知の related_id
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// 重複（同じペア + 同じ種別がすでに存在する）
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// 自己リレーション
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// 無効なリレーション種別
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### 同じペア間の複数種別

同じペアに複数の異なるリレーション種別を持てます:

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## リレーションの一覧表示

```php
// すべてのリレーション
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// 種別によるフィルター
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// 未知の記事
GET /articles/9999/relations
→ 404
```

## リレーションの削除

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// 逆方向も自動的に削除される
GET /articles/2/relations
→ 200  {"data": []}  // 1 への "related" がなくなった

// 見つからない
DELETE /articles/1/relations/2?type=related
→ 404

// 種別クエリパラメーターがない
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

両方の挿入/削除をトランザクションでラップしてください — 一方が失敗した場合、どちらもコミットされません。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 記事の存在確認なしにリレーションを挿入する | FK 違反またはサイレントな 0 行挿入；未知の ID には常に 404 を |
| 正方向 + 逆方向挿入をトランザクションで囲まない | 部分的な失敗により非対称データが残る（A→B は存在するが B→A は存在しない） |
| `UNIQUE(article_id, related_id, relation_type)` なし | 重複エッジが一覧数を膨らませる |
| 自己リレーションを許可する | リレーション走査でサイクルが発生；自分自身の `sequel` は意味がない |
| すべての種別に対称を仮定するハードコード | `sequel`→`sequel`（誤り）ではなく `prequel` |
| 正方向エッジのみを削除する | 逆方向の孤立が残る；A が削除された後も B は A を `prequel` として「見る」 |
