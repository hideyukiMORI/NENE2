---
title: "Content Versioning の実装ガイド"
category: product
tags: [versioning, append-only, rollback, history, content]
difficulty: intermediate
related: [document-versioning, article-versioning-api, draft-publish-workflow]
---

# Content Versioning の実装ガイド

## 概要

このガイドでは NENE2 を使ってコンテンツバージョニング（全履歴保存・特定バージョン参照・ロールバック）を実装する方法を説明します。
記事の変更を append-only で全バージョン保持し、任意のリビジョンへのロールバックを提供します。

---

## DB スキーマ

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

`articles` は現在の最新バージョンを持つ親テーブル。
`article_versions` は **append-only** でコンテンツ変更履歴を積み上げる。

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/articles` | 記事作成（v1 として初回コミット） |
| GET | `/articles/{id}` | 最新バージョン取得 |
| PUT | `/articles/{id}` | 更新（新バージョンを append） |
| GET | `/articles/{id}/versions` | バージョン一覧 |
| GET | `/articles/{id}/versions/{version}` | 特定バージョン取得 |
| POST | `/articles/{id}/rollback` | 指定バージョンへロールバック |

---

## 設計のポイント

### Append-Only バージョニング

update/rollback の両方が**新バージョンを append する**。既存行を上書きしない:

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

**利点**: 任意のバージョンが常に参照可能。DB ロールバックと論理ロールバックが独立する。

### ロールバック = 新バージョンとして保存

ロールバックは「特定バージョンの内容で新しいバージョンを作る」操作。
これにより **ロールバック自体が履歴に残り**、監査に使える:

```
v1: Original title
v2: Modified title
v3: Original title  ← rollback to v1 はここに新バージョンとして保存
```

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target      = $this->findVersion($id, $version);   // 巻き戻し対象
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    // 対象バージョンの内容を新バージョンとして保存
    $this->db->insert('UPDATE articles SET title = ?, body = ?, current_version = ? ...', [...]);
    $this->db->insert('INSERT INTO article_versions ...', [$id, $nextVersion, $target['title'], $target['body'], $now]);
    return true;
}
```

### バージョン一覧は本文を除く

一覧 API では `body` を除いてメタデータのみ返す。個別取得時に `body` を含める:

```
GET /articles/{id}/versions → [{version: 1, title: "...", created_at: "..."}, ...]
GET /articles/{id}/versions/1 → {version: 1, title: "...", body: "...", created_at: "..."}
```

### PHPStan: nullable 戻り値と null チェックの整合

ロールバック後に `find()` を再呼び出しするとき、PHPStan が null チェックを「常に真」と見なす場合がある。
`formatArticle(?array)` が null を受け入れるよう設計することで assert を不要にできる:

```php
// NG: assert が PHPStan に「常に真」と見なされる
$article = $this->repo->find($id);
assert($article !== null);
return $this->json->create($this->formatArticle($article));

// OK: formatArticle が null を受け入れる設計にする
return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
```

---

## レスポンス例

### POST /articles

```json
{
  "id": 1,
  "title": "My Post",
  "body": "Hello world",
  "current_version": 1,
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-01-01T00:00:00Z"
}
```

### GET /articles/{id}/versions

```json
{
  "versions": [
    {"id": 1, "article_id": 1, "version": 1, "title": "My Post", "created_at": "..."},
    {"id": 2, "article_id": 1, "version": 2, "title": "Updated", "created_at": "..."}
  ],
  "count": 2
}
```

### POST /articles/{id}/rollback

```json
{
  "id": 1,
  "title": "My Post",
  "current_version": 3,
  "rolled_back_from": 1
}
```

---

## 参照実装

`../NENE2-FT/contentvlog/` — FT162 フィールドトライアル（18 テスト・append-only 履歴・ロールバック）
