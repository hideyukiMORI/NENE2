# 操作指南：多语言内容 API

> **FT 参考**：FT232（`NENE2-FT/i18nlog`）——多语言内容 API
> **ATK**：FT232——破解者思维攻击测试（ATK-01 至 ATK-12）

演示一个多语言文章 API，内容以区域设置键控的翻译形式存储，与文章记录本身分离。支持 BCP 47 区域设置校验、翻译的 upsert 语义、内容协商的区域设置回退，以及按文章管理发布/草稿状态。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|-----------------------------------------|-----------------------------------------------|
| `POST` | `/articles`                             | 创建文章（草稿或已发布）        |
| `GET`  | `/articles`                             | 列出已发布文章（可选 `?locale=`）|
| `GET`  | `/articles/{id}`                        | 获取单篇文章（可选 `?locale=`）  |
| `PUT`  | `/articles/{id}/translations/{locale}`  | 创建或更新翻译（upsert）         |

---

## 创建文章

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` 设置当请求的区域设置不可用时的回退语言。`published` 控制列表可见性——只有已发布的文章出现在 `GET /articles` 中。

```php
$defaultLocale = isset($body['default_locale']) && is_string($body['default_locale'])
    ? trim($body['default_locale']) : 'en';
$published = isset($body['published']) && $body['published'] === true;

if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLocale)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'default_locale', 'code' => 'invalid',
                      'message' => 'default_locale must be a BCP 47 language tag (e.g. en, ja, fr-FR).']],
    ]);
}
```

`$body['published'] === true`（严格相等）意味着 JSON `true` 设置该标志——任何其他值（字符串 `"true"`、整数 `1`、省略）使文章保持草稿状态。

---

## BCP 47 区域设置校验

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

接受：
- 两个小写字母：`en`、`ja`、`fr`、`de`
- 两个小写字母 + 连字符 + 两个大写字母：`fr-FR`、`zh-TW`、`pt-BR`

拒绝：
- 错误大小写：`EN`、`en_US`、`En`
- 下划线：`en_US`（BCP 47 使用连字符）
- 超出地区的子标签：`zh-Hant-TW`
- 路径遍历：`../../etc/passwd`
- 空字符串：`""`

该正则表达式对常见的 `language` 和 `language-REGION` 形式已足够。对于完整的 BCP 47 支持（脚本代码、变体标签），需要专用库。

---

## 翻译 Upsert

`PUT /articles/{id}/translations/{locale}` 如果翻译不存在则创建，如果存在则更新——幂等，采用最后写入胜出语义：

```php
public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): Translation
{
    $existing = $this->executor->fetchAll(
        'SELECT * FROM article_translations WHERE article_id = ? AND locale = ?',
        [$articleId, $locale],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE article_translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
            [$title, $body, $now, $articleId, $locale],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO article_translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
    }
    // ... 获取并返回行
}
```

数据库结构中的 `UNIQUE(article_id, locale)` 约束作为安全网；应用层的 SELECT-then-INSERT/UPDATE 避免静默冲突解析，并支持返回已持久化的行。

请求体校验拒绝空标题或正文：

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
$text  = isset($body['body'])  && is_string($body['body'])  ? trim($body['body'])  : '';

$errors = [];
if ($title === '') {
    $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'title is required.'];
}
if ($text === '') {
    $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
}
```

空检查前的 `trim()` 确保仅含空白的字符串也会校验失败。

---

## 内容协商的区域设置回退

当调用者传入 `?locale=fr` 时，`Article` 实体查找请求的区域设置，如果不存在翻译则回退到 `default_locale`：

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}

public function toArray(?string $locale = null): array
{
    $translation = $locale !== null
        ? $this->getTranslationWithFallback($locale)
        : null;

    return [
        'id'             => $this->id,
        'default_locale' => $this->defaultLocale,
        'published'      => $this->published,
        'title'          => $translation?->title,    // 无翻译时为 null
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // 指示实际提供的区域设置
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

响应中的 `locale` 字段告诉调用者实际提供了哪个区域设置——在回退发生时很有用（`?locale=zh` → 因为还没有中文翻译，文章提供 `en` 翻译）。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT    NOT NULL DEFAULT 'en',
    published      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale     TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(article_id, locale)
);
```

关键设计选择：
- `published` 存储为 `INTEGER`（SQLite 布尔值：0/1）；PHP 通过 `(bool) $row['published']` 读取。
- `UNIQUE(article_id, locale)` 强制每篇文章每个区域设置最多一条翻译。
- DB 中无语言校验——应用层强制 BCP 47 格式。
- `article_translations.body` 是纯文本；JSON API 调用者负责在 HTML 中渲染前进行消毒。

---

## ATK——破解者思维攻击测试（FT232）

### ATK-01 — 任何端点都无需认证

**攻击**：无需任何凭据创建或修改文章。

```bash
curl -s -X POST http://localhost:8200/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**观察结果**：`201 Created`——无需令牌。任何调用者都可以创建、翻译或发布文章。

**结论**：⚠️ EXPOSED（FT232 演示设计如此）。生产环境请添加认证和授权。对 `POST /articles` 和 `PUT .../translations/{locale}` 要求写入者或管理员角色。

---

### ATK-02 — 区域设置路径参数中的路径遍历

**攻击**：使用路径遍历或 shell 元字符字符串作为 `{locale}` 路径参数。

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**观察结果**：BCP 47 正则 `/^[a-z]{2}(-[A-Z]{2})?$/` 拒绝所有这些——没有一个匹配两个小写字母（可选跟随连字符和两个大写字母）。响应：`422 Unprocessable Entity`。

**结论**：🚫 BLOCKED——以 `^` 和 `$` 锚定的严格正则拒绝遍历序列。

---

### ATK-03 — 通过区域设置路径参数的 SQL 注入

**攻击**：在 `{locale}` 值中嵌入 SQL 元字符。

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**观察结果**：
1. BCP 47 正则立即拒绝这些字符串 → 在任何 SQL 运行之前就返回 `422`。
2. 即使绕过正则，区域设置也作为参数化 `?` 值传递——不与 SQL 进行字符串拼接。

**结论**：🚫 BLOCKED——双重防护：正则允许列表 + 参数化查询。

---

### ATK-04 — IDOR：翻译他人的文章

**攻击**：为攻击者未创建的文章写入翻译。

```bash
# 攻击者知道文章 ID 1 是由另一个用户创建的
curl -s -X PUT http://localhost:8200/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**观察结果**：`200 OK`——翻译被接受并覆盖任何现有的法语翻译。不存在所有权检查。

**结论**：⚠️ EXPOSED——没有所有权模型。添加 `created_by` 列，并在允许写入之前与认证的调用者进行比较。

---

### ATK-05 — 仅含空白的标题或正文

**攻击**：发送修剪后为空白的标题或正文。

```json
{"title": "   ", "body": "\t\n"}
```

**观察结果**：`trim()` 将两者都减为空字符串。两个字段都被添加到 `$errors`。响应：`422 Unprocessable Entity`，带结构化字段错误。

**结论**：🚫 BLOCKED——空字符串检查前的 `trim()` 处理仅含空白的输入。

---

### ATK-06 — 标题或正文中的 XSS 载荷

**攻击**：在翻译字段中存储脚本标签。

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**观察结果**：内容原样存储并在 JSON 中原样返回。API 本身不对输出进行 HTML 编码——它是 JSON API，不是 HTML 渲染器。

**结论**：ACCEPTED BY DESIGN——JSON API 返回原始内容；渲染层（浏览器、移动应用）负责 HTML 转义。在 API 规范中明确记录这一点，以便消费者在渲染不受信任内容时进行消毒。

---

### ATK-07 — 无限制的标题或正文长度

**攻击**：发送数兆字节大小的标题或正文。

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**观察结果**：不强制任何长度限制——超大载荷被存储并返回。内存和 I/O 使用量随载荷大小扩展。SQLite `TEXT` 没有实际大小限制。

**结论**：⚠️ EXPOSED——添加 `maxlength` 检查：
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
同时应用请求大小中间件限制，在解析前限制总字节数。

---

### ATK-08 — BCP 47 大小写和分隔符绕过

**攻击**：尝试语义相似但语法错误的变体。

```
PUT /articles/1/translations/EN        → 大写语言代码
PUT /articles/1/translations/en_US     → 下划线分隔符（POSIX 风格）
PUT /articles/1/translations/en-us     → 小写地区
PUT /articles/1/translations/EN-us     → 混合大小写
PUT /articles/1/translations/fra       → 三字母 ISO 639-2 代码
```

**观察结果**：`/^[a-z]{2}(-[A-Z]{2})?$/` 全部拒绝：
- `EN` — 不符合 `[a-z]`
- `en_US` — `_` 不符合 `(-[A-Z]{2})?`
- `en-us` — `us` 不符合 `[A-Z]`
- `fra` — 三个字符不符合 `{2}` 精确匹配

**结论**：🚫 BLOCKED——正则精确；只有准确的 BCP 47 `ll` 或 `ll-RR` 形式才能通过。

---

### ATK-09 — 对不存在文章的翻译

**攻击**：针对不存在的文章 ID。

```bash
curl -s -X PUT http://localhost:8200/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**观察结果**：`findById(99999)` 返回 `null`。处理器在处理正文之前返回 `404 Not Found`。

**结论**：🚫 BLOCKED——在写入翻译之前验证文章是否存在。

---

### ATK-10 — 无需认证的发布操作

**攻击**：创建已发布文章以绕过草稿审核。

```json
{"default_locale": "en", "published": true}
```

**观察结果**：`201 Created`——`published: true` 立即被接受。不存在草稿审核或审批门控；任何调用者都可以发布。

**结论**：⚠️ EXPOSED（与 ATK-01 根源相同）。发布操作至少应该要求写入者角色。将 `published` 标志从创建载荷中分离——要求显式的 `POST /articles/{id}/publish` 操作并由授权保护。

---

### ATK-11 — `?locale=` 使用未知区域设置时静默回退

**攻击**：使用没有存储翻译的区域设置请求文章。

```
GET /articles/1?locale=zh-TW
```

**观察结果**：`getTranslationWithFallback('zh-TW')` 找不到中文翻译，并回退到 `default_locale`（例如 `en`）。响应中的 `locale` 字段显示 `en`——表明发生了回退。不返回 404 或错误。

**结论**：ACCEPTED BY DESIGN——静默回退对于内容传递是正确的。调用者可以通过比较请求的区域设置与响应中的 `locale` 来检测回退。如果需要严格的区域设置强制，添加 `?strict=1` 参数。

---

### ATK-12 — 非数字文章 ID

**攻击**：将字符串或浮点数作为文章 ID 传入。

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**观察结果**：
- `GET /articles/abc` → 路由匹配 `{id}` 参数；`(int) 'abc'` = `0`。`findById(0)` 返回 `null` → `404 Not Found`。
- `GET /articles/1.5` → `(int) '1.5'` = `1`。如果文章 1 存在，则被返回。这是静默截断，不是错误。

**结论**：⚠️ 部分阻止——非数字字符串解析为 0 并返回 404。浮点数被静默截断。对于严格校验，添加：
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## ATK 汇总

| # | 攻击向量 | 结论 |
|---|---------------|---------|
| ATK-01 | 无认证 | ⚠️ EXPOSED |
| ATK-02 | 区域设置路径遍历 | 🚫 BLOCKED |
| ATK-03 | 通过区域设置的 SQL 注入 | 🚫 BLOCKED |
| ATK-04 | IDOR：翻译他人文章 | ⚠️ EXPOSED |
| ATK-05 | 仅含空白的标题/正文 | 🚫 BLOCKED |
| ATK-06 | 标题/正文中的 XSS | ACCEPTED BY DESIGN |
| ATK-07 | 无限制的标题/正文长度 | ⚠️ EXPOSED |
| ATK-08 | BCP 47 大小写/分隔符绕过 | 🚫 BLOCKED |
| ATK-09 | 对不存在文章的翻译 | 🚫 BLOCKED |
| ATK-10 | 无需认证发布 | ⚠️ EXPOSED |
| ATK-11 | 未知 `?locale=` 静默回退 | ACCEPTED BY DESIGN |
| ATK-12 | 非数字文章 ID | ⚠️ 部分阻止 |

**生产前需修复的真实漏洞**：
1. **ATK-01 / ATK-04 / ATK-10** — 添加认证、所有权检查以及独立的发布操作
2. **ATK-07** — 添加标题和正文长度限制
3. **ATK-12** — 为 ID 参数添加 `ctype_digit()` 守护

---

## 相关操作指南

- [`approval-workflow.md`](approval-workflow.md) — 发布前内容审核的状态机
- [`bulk-status-update.md`](bulk-status-update.md) — 带部分成功的批量变更模式
- [`media-watchlist.md`](media-watchlist.md) — 枚举支持的状态和可选可空字段
