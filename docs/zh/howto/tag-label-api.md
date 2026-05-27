# 操作指南：标签/标记 API

本指南演示一个通用的实体标签 API，可以将任意标签附加到任意实体 ID，并支持基于标签的反向查找。

## 模式概述

- 标签全局存储，通过 slug 标识（`a-z0-9-`，1–50 个字符）。
- 任何实体（通过整数 ID 标识）可以拥有多个标签。
- `POST /tags` — 创建或获取标签（查找或创建；幂等）。
- `GET /tags` — 列出所有已知标签。
- `GET /tags/{tag}/entities` — 反向查找：哪些实体拥有此标签？
- `POST /entities/{entityId}/tags` — 将标签附加到实体。
- `GET /entities/{entityId}/tags` — 列出实体的所有标签。
- `DELETE /entities/{entityId}/tags/{tag}` — 从实体分离标签。

## 数据库结构

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

## 查找或创建模式

标签创建是幂等的——`POST /tags` 首次创建时返回 201，标签已存在时返回 200：

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

`attachTag` 处理器也使用查找或创建，因此客户端无需单独的创建步骤即可附加标签。

## 标签名称验证

标签名称规范化为小写，并通过严格格式正则进行验证：

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

空格、大写字母、下划线和特殊字符均被拒绝。

## 反向查找（标签 → 实体）

`GET /tags/{tag}/entities` 在数据库中标签不存在时返回 404，标签存在但未被使用时返回空数组：

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag not found.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

反向查找的 SQL：

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## 附加/分离的幂等性

向同一实体附加同一标签两次返回 200（而非 201），并附带 `"attached": false`：

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

分离未附加的标签返回 404。

## 实体 ID 验证

实体 ID 使用 `ctype_digit()` 验证，以避免 ReDoS 并确保非负整数：

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## 路由

```
POST   /tags                           创建或获取标签
GET    /tags                           列出所有标签
GET    /tags/{tag}/entities            反向查找：拥有此标签的实体
POST   /entities/{entityId}/tags       向实体附加标签
GET    /entities/{entityId}/tags       列出实体的标签
DELETE /entities/{entityId}/tags/{tag} 从实体分离标签
```

## 参见

- FT209 源码：`../NENE2-FT/taglog/`
- 相关：`docs/howto/note-taking.md`（FT202，基于标签的笔记搜索）
