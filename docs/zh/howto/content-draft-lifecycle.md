# 使用 NENE2 构建内容草稿生命周期（草稿 → 已发布 → 已归档）

本指南介绍如何构建一个带有草稿/发布/归档状态机的文章管理系统，其中只有作者可以进行状态转换，并且只有已发布的文章对读者可见。

**字段测试**：FT142  
**NENE2 版本**：^1.5  
**涵盖主题**：带枚举的状态机、转换守卫、作者所有权检查、状态过滤公开列表、同秒排序稳定性

---

## 我们要构建的内容

- `POST /articles` ——创建文章（始终以 `draft` 状态开始）
- `GET /articles` ——仅列出已发布的文章
- `GET /articles/{id}` ——获取文章（作者可看任意状态；其他人只能看 `published`）
- `PUT /articles/{id}` ——编辑文章（仅限草稿，仅限作者）
- `POST /articles/{id}/publish` ——转换 `draft → published`（仅限作者）
- `POST /articles/{id}/archive` ——转换 `published → archived`（仅限作者）

---

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

`published_at` 和 `archived_at` 可为 null——它们仅在相应的转换时设置。

---

## 带转换守卫的 ArticleStatus 枚举

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

处理器读取当前状态，调用守卫方法，如果转换无效则返回 422：

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

有效转换：
- `draft → published`（通过 publish）
- `published → archived`（通过 archive）
- 没有返回草稿的转换。

---

## 作者可见性——草稿对他人隐藏

非作者无法读取草稿。返回 404（而非 403）以避免泄露文章是否存在：

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

返回 403 会确认文章存在。对于尚未公开的内容，404 是正确的选择。

---

## 同秒排序稳定性

当多篇文章在同一秒内发布时，单独使用 `ORDER BY published_at DESC` 会给出非确定性顺序。添加 `id DESC` 作为排序决胜项：

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

更高的 `id` 意味着创建时间更晚，因此这实际上是在同一秒内按插入顺序排序。

---

## 常见陷阱

| 陷阱 | 修复 |
|------|------|
| 非作者读取草稿时返回 403 | 返回 404——防止内容存在性泄露 |
| 允许 `published → draft` 重新开放 | `canEdit()` 在非 `Draft` 时返回 false；没有"取消发布"端点 |
| 发布已发布的文章 | `canPublish()` 对 `Published` 返回 false → 422 |
| 归档草稿 | `canArchive()` 在非 `Published` 时返回 false → 422 |
| 同一时间戳下的列表顺序不确定 | 添加 `id DESC` 作为次要排序 |
