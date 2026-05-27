# 操作指南：带历史记录的 Slug URL 管理

> **FT 参考**：FT339（`NENE2-FT/sluglog`）— 从标题自动生成 slug、冲突计数器、slug 历史用于旧 slug 的 301 重定向、显式 slug 覆盖、漏洞评估，17 tests / 50+ assertions 全部 PASS。

本指南展示如何从内容标题生成干净的 URL slug、用顺序后缀处理冲突、在历史表中保留旧 slug 用于永久重定向，以及防止常见攻击向量。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/articles` | 创建文章（从标题自动生成 slug） |
| `PUT` | `/articles/{id}` | 更新文章（标题变化时重新生成 slug） |
| `GET` | `/articles/by-slug/{slug}` | 按当前或历史 slug 获取 |
| `GET` | `/articles/{id}/slug-history` | 列出 slug 历史 |

## Slug 生成

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

规则：
1. 全部转换为小写
2. 将非字母数字字符替换为 `-`
3. 合并连续连字符
4. 去除首尾连字符
5. 结果为空时返回 `"untitled"`

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### 冲突解决

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` 是一个 DB 查找回调：`fn(string $s): bool => (bool) $repo->findBySlug($s)`。

## 创建文章

```php
POST /articles
{"title": "My First Post", "body": "Content here."}
→ 201
{
  "id": 1,
  "title": "My First Post",
  "slug": "my-first-post",
  "body": "...",
  "created_at": "..."
}
```

## 更新文章

```php
PUT /articles/1
{"title": "New Title", "body": "Updated content."}
→ 200  {"slug": "new-title", ...}
```

标题变化时，派生新 slug 并将旧 slug 保存到 `slug_history`。

```php
// 标题相同——slug 不变，无历史条目
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // slug 相同

// 显式 slug 覆盖
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// 更新时发生冲突——自动解决
// （如果 "popular" 已存在，重命名为 "popular-2"）
PUT /articles/2  {"title": "Popular", "body": "Body."}
→ 200  {"slug": "popular-2"}

// 未知文章
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## 按 Slug 获取

```php
// 当前 slug → 200
GET /articles/by-slug/new-title
→ 200  {"id": 1, "slug": "new-title", "title": "New Title", ...}

// 旧 slug → 301 重定向
GET /articles/by-slug/my-first-post
→ 301
{
  "redirect": true,
  "canonical_slug": "new-title"
}

// 未知 → 404
GET /articles/by-slug/does-not-exist
→ 404
```

301 响应告知爬虫/客户端将链接更新为规范 slug。

## Slug 历史

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// 新文章——空历史
{"current_slug": "fresh", "slug_history": []}

// 未知文章 → 404
GET /articles/9999/slug-history → 404
```

只有当 slug 实际变化时才会累积历史条目。更新正文而不更改标题，历史记录保持不变。

---

## 漏洞评估

### V-01 — 通过 Slug 的路径遍历 ✅ SAFE

**风险**：攻击者发送 `GET /articles/by-slug/../../../etc/passwd` 来遍历服务器目录。
**结论**：SAFE — Slug 查找使用绑定参数的 SQL `WHERE slug = ?`。路径段从不被解释为文件系统路径。HTTP 层在到达控制器之前会规范化路径中的 `../`。

---

### V-02 — 通过 URL 中的 Slug 的 SQL 注入 ✅ SAFE

**风险**：`GET /articles/by-slug/' OR '1'='1` 泄露所有文章。
**结论**：SAFE — Slug 在 `WHERE slug = ?` 中作为绑定参数传递。无论 slug 值是什么，SQL 注入都是不可能的。

---

### V-03 — Slug 枚举（暴力发现） ⚠️ EXPOSED

**风险**：攻击者迭代常见 slug（`/articles/by-slug/admin`、`/articles/by-slug/secret-doc`）来发现私有文章。
**结论**：EXPOSED — Slug 是人类可读标题的可预测派生物。`GET /articles/by-slug/{slug}` 上没有强制限流或认证。缓解方案：对私有内容要求认证；添加按 IP 限流；对敏感资源考虑使用不透明 ID。

---

### V-04 — Slug 历史 IDOR ✅ SAFE

**风险**：攻击者调用 `GET /articles/{id}/slug-history` 获取其他用户文章的历史，以发现过去的标题。
**结论**：SAFE — Slug 历史是公开元数据。如果文章是公开的，其历史也是如此。如果文章需要授权，对 `/slug-history` 端点一致地应用相同的认证检查。

---

### V-05 — 通过 Slug 历史的无限重定向循环 ✅ SAFE

**风险**：文章 A 重命名为 slug B；文章 B 重命名为 slug A — `GET /by-slug/a` → 重定向到 B → 重定向到 A（无限循环）。
**结论**：SAFE — 实现在 `articles.slug` 中查找**当前** slug，然后仅对旧 slug 检查 `slug_history`。301 响应始终指向当前规范 slug。客户端跟随重定向一跳即可到达规范 slug。

---

### V-06 — Slug 冲突滥用（顺序计数器耗尽） ⚠️ EXPOSED

**风险**：攻击者创建数千篇标题为"popular"的文章来占用"popular-2"到"popular-9999"，然后删除它们——或迫使昂贵的计数器扫描。
**结论**：EXPOSED — 文章创建没有限流。`makeUnique` 计数器扫描需要 O(n) 次 DB 查询。缓解方案：对每个用户限制 POST /articles 的频率；将 slug 计数器上限设为合理值（例如 99）；超过阈值后使用随机后缀。

---

### V-07 — 显式 Slug 注入（覆盖其他文章的 Slug） ✅ SAFE

**风险**：攻击者使用 `PUT /articles/2  {"slug": "popular"}` 其中 "popular" 属于文章 1。
**结论**：SAFE — `articles.slug` 有 `UNIQUE` 约束。尝试设置已被其他文章占用的 slug 会触发 DB 约束冲突，转换为 409 Conflict。

---

### V-08 — Unicode/同形字 Slug 攻击 ⚠️ EXPOSED

**风险**：攻击者创建 Unicode 标题的文章，该标题规范化后与现有 ASCII slug 的字节相同（例如 `café` → `caf-`），创建视觉上容易混淆的 URL。
**结论**：EXPOSED — `SlugHelper::fromTitle()` 使用 `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`。非 ASCII 字符被替换为 `-`，可能导致意外冲突或空 slug。缓解方案：slug 生成前将 Unicode 规范化为 ASCII 音译（例如 `iconv`）；规范化后将所有非 ASCII 字符视为 `-`。

---

### V-09 — 通过标题存储在 Slug 中的 XSS ✅ SAFE

**风险**：标题 `<script>alert(1)</script>` 生成 slug `script-alert-1-script`——安全的字母数字输出。
**结论**：SAFE — `SlugHelper::fromTitle()` 将所有非字母数字字符替换为 `-`。slug 输出始终是 `[a-z0-9-]`，使得通过 slug 进行 HTML 注入不可能。

---

### V-10 — 旧 Slug 查找泄露重命名内容 ⚠️ EXPOSED

**风险**：文章从"secret-plan-v1"重命名为"public-announcement"；攻击者使用旧 slug 通过重定向响应中的 `canonical_slug` 发现原始标题。
**结论**：EXPOSED — 301 响应暴露新规范 slug，可能泄露重命名的内容。slug 历史端点也会泄露所有旧名称。对于敏感重命名，用墓碑替代旧 slug 而不揭示新位置；或使用不透明 slug。

---

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|------|------|
| V-01 | 通过 slug 的路径遍历 | ✅ SAFE |
| V-02 | 通过 slug 的 SQL 注入 | ✅ SAFE |
| V-03 | Slug 枚举 | ⚠️ EXPOSED |
| V-04 | Slug 历史 IDOR | ✅ SAFE |
| V-05 | 无限重定向循环 | ✅ SAFE |
| V-06 | 冲突计数器耗尽 | ⚠️ EXPOSED |
| V-07 | 显式 slug 覆盖 | ✅ SAFE |
| V-08 | Unicode 同形字攻击 | ⚠️ EXPOSED |
| V-09 | 通过标题的 XSS | ✅ SAFE |
| V-10 | 旧 slug 泄露重命名内容 | ⚠️ EXPOSED |

**6 SAFE，4 EXPOSED** — 限制文章创建频率；对私有内容添加认证；slug 生成前规范化 Unicode；对敏感重命名考虑仅使用墓碑式 slug 历史。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 将 slug 直接插入 SQL | 通过 slug 路径参数的 SQL 注入 |
| 文章删除时硬删除 slug 历史 | 旧 URL 返回 404 而非 301；SEO 损失和链接失效 |
| `articles.slug` 无 `UNIQUE` 约束 | 并发插入导致重复 slug |
| 标题更新时保持旧 slug 不变 | Slug 偏移——URL 不再反映内容 |
| `makeUnique` 无计数器上限 | 攻击者通过批量创建耗尽计数器 |
| 使用 `!==` 比较现有 slug | 类型强制转换意外；slug 比较始终用 `===` |
