# 操作指南：文章版本控制 API

> **FT 参考**：FT249（`NENE2-FT/contentvlog`）——文章版本控制 API
> **漏洞评估**：FT249——漏洞评估（V-01 至 V-10）

演示一个文章版本控制系统，`articles` 表上的 `current_version` 整数列跟踪最新版本，每次更新追加到 `article_versions`，回滚从历史内容创建新版本。包含对未认证设计的漏洞评估。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|-----------------------------------|------------------------------------------------------|
| `POST` | `/articles` | 创建文章（版本 1） |
| `GET` | `/articles/{id}` | 获取文章（当前内容） |
| `PUT` | `/articles/{id}` | 更新文章（创建新版本） |
| `GET` | `/articles/{id}/versions` | 列出版本历史（仅元数据） |
| `GET` | `/articles/{id}/versions/{version}` | 获取特定版本 |
| `POST` | `/articles/{id}/rollback` | 回滚到某个版本（创建新版本） |

---

## 数据库结构：`current_version` 整数列

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

`current_version` 列存储当前内容的版本号。`UNIQUE(article_id, version)` 防止同一文章出现重复的版本号。

**与 `is_current` 标志方案的比较**（见 `document-versioning.md`）：

| 方案 | `current_version` 整数 | `is_current` 标志 |
|---|---|---|
| 数据库结构 | `articles` 表上的列 | `versions` 表上的列 |
| 当前版本查找 | `SELECT * FROM articles WHERE id = ?`（无需 JOIN） | `LEFT JOIN ... ON dv.is_current = 1` |
| 版本号追踪 | 父行上的显式整数 | 从行数或 MAX 隐式推断 |
| 原子性 | 更新 articles + 插入 version（2 次写入） | 更新标志 + 插入（2 次写入） |

---

## 创建：两次写入初始化

创建文章时向两张表写入：

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

两次写入没有事务包裹。如果第二次插入失败，`articles` 行存在但 `article_versions` 没有对应的条目——文章处于版本 1 但没有历史记录。生产环境应将两次写入包裹在 `$txManager->transactional()` 中。

---

## 更新：读取-然后-递增模式

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
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

版本号在 PHP 中读取、递增，然后写回。没有事务时，并发更新可能产生重复的版本号——`UNIQUE(article_id, version)` 约束会捕获此情况，但 `UPDATE articles` 可能在 `INSERT article_versions` 失败之前就成功，导致文章的 `current_version` 超前于其历史记录。

---

## 回滚：非破坏性（复制为新版本）

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

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

回滚不会删除版本——它将目标版本的内容复制为新的（当前）版本。历史记录始终被保留。如果文章处于版本 5 并回滚到版本 2：

```
v1 → v2 → v3 → v4 → v5 → v6（v2 内容的副本）
```

---

## 版本列表：仅元数据（不含正文）

`GET /articles/{id}/versions` 返回版本元数据，不包含完整正文：

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` 从列表中排除——调用者必须通过 `GET /articles/{id}/versions/{version}` 获取单个版本的内容。这避免了在列表响应中发送可能很大的内容。

---

## 漏洞评估（FT249）

### V-01 — 无认证：任何调用者都可以更新或删除任何文章

**风险**：所有端点均未经认证。

**影响**：攻击者可以覆写任何文章，将其内容回滚到之前的版本，或枚举所有版本历史。

**结论**：**EXPOSED**——添加认证（API 密钥、JWT 或会话）。更新/回滚应要求文章所有者经过认证。

---

### V-02 — 无所有权检查：任何已认证用户都可以修改任何文章

**风险**：即使有认证，也没有按所有权范围的查询。任何已认证用户都可以更新任何其他用户的文章。

**影响**：没有 `WHERE id = ? AND owner_id = ?` 时，文章 ID 可枚举且任何有效令牌都可修改。

**结论**：**EXPOSED**——在 `articles` 中添加 `owner_id` 列。在所有写入操作中使用 `WHERE id = ? AND owner_id = ?` 强制所有权。

---

### V-03 — IDOR：读取其他用户的版本历史

**风险**：`GET /articles/{id}/versions` 返回任意文章 ID 的所有版本历史。

**影响**：攻击者可以枚举作者可能不打算公开的草稿内容历史。

**结论**：**EXPOSED**——按所有权范围所有读取：只有文章所有者（或具有明确读取权限的角色）才能查看版本历史。

---

### V-04 — 版本号递增的竞争条件

**风险**：`update()` 读取 `current_version`，在 PHP 中递增，然后写回。读写序列没有事务或行锁保护。

**攻击**：两个并发的 `PUT /articles/1` 请求都读取 `current_version = 3`。两者都计算出 `nextVersion = 4`。一个成功（插入版本 4）；另一个因 `UNIQUE(article_id, version)` 约束失败——但 `UPDATE articles` 可能已经成功，为两者都将 `current_version` 设置为 4，而历史记录中只有一条版本记录。

**结论**：**EXPOSED**——将 `find` + `UPDATE` + `INSERT` 包裹在数据库事务中。使用 `UPDATE articles SET current_version = current_version + 1` 进行原子递增。

---

### V-05 — 通过 title 或 body 进行 SQL 注入

**攻击**：嵌入 SQL 元字符。

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**观察结果**：值被绑定为参数化的 `?` 占位符。注入内容作为字面文本存储。

**结论**：**BLOCKED**——参数化查询防止 SQL 注入。

---

### V-06 — 版本枚举：无边界的历史访问

**风险**：`GET /articles/{id}/versions` 返回完整版本历史，没有分页或限制。

**影响**：有数千个版本的文章在单个响应中返回所有行，导致内存压力和慢查询。

**结论**：**EXPOSED**——在版本列表端点添加分页（`LIMIT ? OFFSET ?`）。考虑限制每篇文章的最大版本数。

---

### V-07 — 非事务性的两次写入操作

**风险**：`create()` 和 `update()` 都在没有事务包裹的情况下执行两次顺序写入。

**影响**：如果第二次写入失败（例如，约束违规、连接错误），系统处于不一致状态：`articles.current_version` 可能与 `article_versions` 行数不一致，或文章存在但没有版本记录。

**结论**：**EXPOSED**——将配对写入包裹在 `DatabaseTransactionManagerInterface::transactional()` 中。

---

### V-08 — 回滚到另一篇文章的版本

**攻击**：提交一个回滚请求，使用另一篇文章存在的版本号。

```bash
# 文章 1 有版本 1-3；文章 2 有版本 1
POST /articles/1/rollback  {"version": 1}
```

**观察结果**：`findVersion(articleId=1, version=1)` 使用 `WHERE article_id = ? AND version = ?`——它只查找属于文章 1 的版本。属于文章 2 的版本不会被返回。

**结论**：**BLOCKED**——版本查找通过 `article_id` 进行范围限定。

---

### V-09 — 大正文：文章内容无大小限制

**风险**：`body` 接受任意长度的字符串，没有验证。

**影响**：每次读取时，数兆字节的正文都会消耗存储和内存。

**结论**：**EXPOSED**——添加正文长度检查（例如，`strlen($body) > 1_000_000 → 422`）。依赖请求大小中间件作为外层限制。

---

### V-10 — 回滚到 `version = 0` 或负版本号

**攻击**：提交版本号为 0 或 -1 的回滚请求。

```json
{"version": 0}
{"version": -1}
```

**观察结果**：`(int) $body['version']` 接受任意整数。`findVersion($id, 0)` 和 `findVersion($id, -1)` 返回 `null`（不存在此版本）→ `404 Not Found`。没有版本 0 被存储（版本从 1 开始）。

**结论**：**BLOCKED**——`findVersion` 对不存在的版本返回 `null`；不需要特殊处理。

---

## 漏洞总结

| # | 漏洞 | 结论 |
|---|---------------|---------|
| V-01 | 写入端点无认证 | EXPOSED |
| V-02 | 无所有权检查（任何用户都可修改任何文章） | EXPOSED |
| V-03 | 版本历史的 IDOR | EXPOSED |
| V-04 | 版本号递增的竞争条件 | EXPOSED |
| V-05 | 通过 title/body 进行 SQL 注入 | BLOCKED |
| V-06 | 无边界的版本列表（无分页） | EXPOSED |
| V-07 | 非事务性的配对写入 | EXPOSED |
| V-08 | 回滚到另一篇文章的版本 | BLOCKED |
| V-09 | 无正文大小限制 | EXPOSED |
| V-10 | 回滚到版本 0/负数 | BLOCKED |

**生产前的关键修复**：
1. **V-01 / V-02 / V-03** ——添加认证和 `owner_id` 所有权强制
2. **V-04 / V-07** ——将所有多次写入操作包裹在 `transactional()` 中；使用原子版本递增
3. **V-06** ——为版本列表添加 `LIMIT ? OFFSET ?` 分页
4. **V-09** ——添加正文大小验证

---

## 相关操作指南

- [`document-versioning.md`](document-versioning.md) ——使用 `DatabaseTransactionManagerInterface` 的 `is_current` 标志方案
- [`content-versioning.md`](content-versioning.md) ——使用线性版本号的内容版本控制
- [`transactions.md`](transactions.md) ——`DatabaseTransactionManagerInterface` 模式
- [`optimistic-locking.md`](optimistic-locking.md) ——使用版本列+条件 UPDATE 防止竞争条件
