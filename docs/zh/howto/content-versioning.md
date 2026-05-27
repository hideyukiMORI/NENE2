# 内容版本控制实现指南

## 概述

本指南介绍如何使用 NENE2 实现内容版本控制（保存完整历史记录、引用特定版本、回滚）。以 append-only 方式保留文章的所有版本变更历史，并提供回滚到任意修订版本的功能。

---

## 数据库结构

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

`articles` 是持有当前最新版本的父表。`article_versions` 以 **append-only** 方式累积内容变更历史。

---

## 端点设计

| 方法 | 路径 | 描述 |
|------|------|------|
| POST | `/articles` | 创建文章（作为 v1 初始提交） |
| GET | `/articles/{id}` | 获取最新版本 |
| PUT | `/articles/{id}` | 更新（追加新版本） |
| GET | `/articles/{id}/versions` | 版本列表 |
| GET | `/articles/{id}/versions/{version}` | 获取特定版本 |
| POST | `/articles/{id}/rollback` | 回滚到指定版本 |

---

## 设计要点

### Append-Only 版本控制

更新和回滚都**追加新版本**。不覆盖现有行：

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

**优点**：任意版本始终可引用。数据库回滚与逻辑回滚相互独立。

### 回滚 = 保存为新版本

回滚是"用指定版本的内容创建新版本"的操作。这样**回滚本身也会留在历史记录中**，可用于审计：

```
v1: Original title
v2: Modified title
v3: Original title  ← 回滚到 v1 在此作为新版本保存
```

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target      = $this->findVersion($id, $version);   // 回滚目标
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    // 将目标版本的内容保存为新版本
    $this->db->insert('UPDATE articles SET title = ?, body = ?, current_version = ? ...', [...]);
    $this->db->insert('INSERT INTO article_versions ...', [$id, $nextVersion, $target['title'], $target['body'], $now]);
    return true;
}
```

### 版本列表不含正文

列表 API 只返回元数据，不含 `body`。获取单个版本时才包含 `body`：

```
GET /articles/{id}/versions → [{version: 1, title: "...", created_at: "..."}, ...]
GET /articles/{id}/versions/1 → {version: 1, title: "...", body: "...", created_at: "..."}
```

### PHPStan：nullable 返回值与 null 检查的一致性

回滚后重新调用 `find()` 时，PHPStan 可能将 null 检查视为"始终为真"。将 `formatArticle(?array)` 设计为接受 null 可以避免使用 assert：

```php
// 不好：assert 被 PHPStan 视为"始终为真"
$article = $this->repo->find($id);
assert($article !== null);
return $this->json->create($this->formatArticle($article));

// 好：将 formatArticle 设计为接受 null
return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
```

---

## 响应示例

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

## 参考实现

`../NENE2-FT/contentvlog/` — FT162 字段测试（18 个测试，append-only 历史记录，回滚）
