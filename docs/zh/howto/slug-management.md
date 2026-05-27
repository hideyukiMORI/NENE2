# Slug 管理——唯一 URL Slug（带冲突解决和历史记录）

从标题生成 URL 安全的 slug，自动解决冲突，并维护 **slug 历史表**，以便旧 slug 可以重定向到规范 URL，而不会破坏入站链接。

**参考实现：** [hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples) 中的 `FT174 sluglog`

---

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- 当前规范 slug
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- 保留旧 slug 以支持重定向
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- 重定向来源；UNIQUE 防止重复
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## Slug 生成

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  如果 slug 已被占用则返回 true。
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### 唯一性检查——两个表都要检查

检查 slug 是否"已被占用"时，需要同时检查 `articles.slug` 和 `slug_history.old_slug`。否则新文章可能声明一个仍作为重定向来源活跃使用的 slug：

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## 带重定向提示的 Slug 查找

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. 检查当前 slug 列（200 OK）
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. 检查 slug 历史（301 重定向提示）
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

处理器随后返回带 `canonical_slug` 和 `data` 的 HTTP 301：

```json
// GET /articles/by-slug/old-title  →  301
{
  "redirect": true,
  "canonical_slug": "new-title",
  "data": { "id": 1, "slug": "new-title", ... }
}
```

---

## Slug 更新——记录历史

文章重命名时，将旧 slug 移至 `slug_history`：

```php
if ($newSlug !== $article->slug) {
    // 仅在不在历史记录中时插入（幂等）
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### 更新时的冲突处理

为已更新文章计算新 slug 时，从"存在性"检查中排除文章自身的**当前** slug——否则会不必要地递增到 `-2`：

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/articles` | 创建文章——slug 自动从标题派生 |
| `GET` | `/articles/{id}` | 按数字 ID 获取 |
| `GET` | `/articles/by-slug/{slug}` | 按 slug 获取（200 当前 / 301 历史 / 404） |
| `PUT` | `/articles/{id}` | 更新标题/内容/slug；旧 slug → 历史记录 |
| `GET` | `/articles/{id}/slug-history` | 列出历史 slug |

---

## 冲突场景

| 场景 | 结果 |
|---|---|
| 第一个"Hello World" | `hello-world` |
| 第二个"Hello World" | `hello-world-2` |
| 第三个"Hello World" | `hello-world-3` |
| 文章从 `hello` 重命名为已被占用的 slug | `taken-slug-2` |
| 标题相同，slug 不变 | 无历史条目，slug 不变 |
| 旧 slug 匹配历史条目 | 301 重定向响应 |

---

## 领域层结构

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## 参见

- [软删除](./soft-delete.md) — 将 slug 历史与软删除记录结合
- [内容版本控制](./content-versioning.md) — 版本历史与 slug 历史并存
- [内容草稿生命周期](./content-draft-lifecycle.md) — 草稿状态下的 slug 行为
