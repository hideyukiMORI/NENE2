# 操作指南：草稿 → 发布 → 归档工作流

> **FT 参考**：FT305（`NENE2-FT/draftlog`）——文章生命周期状态机：draft→published→archived 单向状态转换，仅作者可写，非作者只能看到已发布文章（草稿返回 404），已发布文章不可编辑，公开列表排除草稿和已归档文章，20 个测试 / 28 个断言全部通过。

本指南演示如何实现内容生命周期管理：文章从草稿开始，发布后对外可见，归档后从公开列表中移除。

## 数据库结构

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

`CHECK (status IN (...))` 确保只存储已知状态。`published_at` 和 `archived_at` 时间戳记录状态转换发生的时间。

## 状态机

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| 转换 | 前置条件 | 违反时的错误 |
|------|---------|------------|
| draft → published | 状态必须为 `'draft'` | 422 |
| published → archived | 状态必须为 `'published'` | 422 |
| published → draft | ❌ 不允许 | — |
| archived → 任意状态 | ❌ 不允许 | — |

```php
// 发布处理器
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// 归档处理器
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/articles` | `X-User-Id` | 创建文章（初始为草稿） |
| `GET` | `/articles` | — | 仅列出已发布文章 |
| `GET` | `/articles/{id}` | `X-User-Id` | 获取文章（带可见性检查） |
| `PUT` | `/articles/{id}` | `X-User-Id`（作者） | 更新草稿（仅草稿可编辑） |
| `POST` | `/articles/{id}/publish` | `X-User-Id`（作者） | 发布 |
| `POST` | `/articles/{id}/archive` | `X-User-Id`（作者） | 归档 |

## 新文章始终从草稿开始

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

`status` 在创建时始终为 `'draft'`，无论请求体中是否有其他值。客户端无法选择初始状态。

## 可见性——非作者只能看到已发布内容

```php
// 非作者只能看到已发布的文章
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

未发布的文章（草稿或已归档）对非作者返回 404。这可以防止：
- 其他用户读取未发布的草稿
- 泄露文章是否已被归档

## 已发布文章不可编辑

```php
// 更新处理器——只有草稿可编辑
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

一旦发布，文章内容即被冻结。在此设计中，发布是单向门——作者必须先取消发布（本设计不支持）才能编辑。

## 列表端点——仅展示已发布内容

```php
// 仓库：SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

列表端点仅过滤 `status = 'published'`。草稿和已归档文章从不出现在公开列表中。

## 仅作者可执行写操作

所有写操作（更新、发布、归档）均验证操作者是文章的作者：

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 允许在创建请求体中指定状态 | 客户端绕过审核工作流直接将文章设为 `'published'` |
| 非作者访问草稿时返回 403 | 泄露文章存在；应用 404 隐藏未发布内容 |
| 允许编辑已发布文章 | 事后修改已上线内容；违背读者信任 |
| 允许 archived → published 转换 | 已归档文章意外重新出现 |
| 在公开列表中展示草稿 | 未准备好的内容在发布前被暴露 |
| 无 `CHECK (status IN (...))` | 直接 DB 插入可设置任意状态字符串 |
| 已归档文章对非作者返回 200 | 告知非作者该内容曾存在且已被归档 |
