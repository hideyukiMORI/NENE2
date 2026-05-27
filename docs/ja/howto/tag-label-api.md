# ハウツー: タグ / ラベル API

このガイドでは、任意のタグを任意のエンティティ ID に添付でき、タグベースの逆引き検索が可能な汎用エンティティタグ付け API を実演します。

## パターン概要

- タグはグローバルに保存され、スラグ（`a-z0-9-`、1〜50 文字）で識別されます。
- 任意のエンティティ（整数 ID で識別）は複数のタグを持てます。
- `POST /tags` — タグを作成または取得する（find-or-create; 冪等）。
- `GET /tags` — すべての既知のタグを一覧表示する。
- `GET /tags/{tag}/entities` — 逆引き: どのエンティティがこのタグを持っているか？
- `POST /entities/{entityId}/tags` — エンティティにタグを添付する。
- `GET /entities/{entityId}/tags` — エンティティのすべてのタグを一覧表示する。
- `DELETE /entities/{entityId}/tags/{tag}` — エンティティからタグを切り離す。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS entity_tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id  INTEGER NOT NULL,
    tag_id     INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    UNIQUE (entity_id, tag_id)
);
```

## Find-or-Create パターン

タグ作成は冪等です — `POST /tags` は初回作成時に 201、タグがすでに存在する場合は 200 を返します:

```php
public function findOrCreate(string $name): array
{
    $existing = $this->findByName($name);
    if ($existing !== null) {
        return $existing;
    }
    $this->pdo->prepare(
        'INSERT INTO tags (name, created_at) VALUES (:name, :now)'
    )->execute([':name' => $name, ':now' => $this->now()]);

    return $this->findByName($name) ?? [];
}
```

`attachTag` ハンドラーも find-or-create を使用するため、クライアントは別途作成ステップなしにタグを添付できます。

## タグ名バリデーション

タグ名は小文字に正規化され、厳密なフォーマット正規表現で検証されます:

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

スペース、大文字、アンダースコア、特殊文字はすべて拒否されます。

## 逆引き（タグ → エンティティ）

`GET /tags/{tag}/entities` は、タグがデータベースに存在しない場合は 404 を返し、存在するが未使用の場合は空の配列を返します:

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag not found.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

逆引き用 SQL:

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## 添付/切り離しの冪等性

同じエンティティに同じタグを 2 回添付すると `"attached": false` で 200 が返されます（201 ではなく）:

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

添付されていないタグを切り離すと 404 が返されます。

## エンティティ ID バリデーション

エンティティ ID は ReDoS を防ぎ非負整数を保証するために `ctype_digit()` で検証されます:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## ルート

```
POST   /tags                           タグを作成または取得する
GET    /tags                           すべてのタグを一覧表示する
GET    /tags/{tag}/entities            逆引き: このタグを持つエンティティ
POST   /entities/{entityId}/tags       エンティティにタグを添付する
GET    /entities/{entityId}/tags       エンティティのタグを一覧表示する
DELETE /entities/{entityId}/tags/{tag} エンティティからタグを切り離す
```

## 関連

- FT209 ソース: `../NENE2-FT/taglog/`
- 関連: `docs/howto/note-taking.md`（FT202、タグベースのノート検索）
