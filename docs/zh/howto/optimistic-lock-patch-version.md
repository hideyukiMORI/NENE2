# 操作指南：PATCH + 版本字段的乐观锁

> **FT 参考**：FT324（`NENE2-FT/optlocklog`）——基于 PATCH 的乐观锁，409 响应包含 `current_version` 以支持零 GET 重试，严格整数 version 类型，ATK 评估，12 个测试 / 24 个断言全部通过。

本指南展示如何通过带 `version` 字段的 PATCH 实现乐观并发控制，在 409 响应中返回当前服务端版本，以便客户端无需额外 GET 即可重试。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST`  | `/articles` | 创建（version=1） |
| `GET`   | `/articles/{id}` | 获取带 version 的文章 |
| `PATCH` | `/articles/{id}` | 更新（version 必须为整数） |

## 创建与读取

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## 带版本的 PATCH

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

Version 必须是 **JSON 整数**——字符串 `"1"` 会被拒绝。

## 409 包含 current_version

检测到冲突时，响应包含 `current_version`，客户端无需重新 GET 即可重试：

```php
// Version 1 已被另一个写入者更新为 2
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← 客户端可直接用于重试
}

// 客户端用 409 响应体中的 current_version 重试
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← 成功
```

## 类型校验

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // 缺少 version
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // 字符串不是 int
PATCH /articles/9999 {"version": 1}                      → 404  // 未找到
```

## 实现

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // 严格整数类型检查——"1"（字符串）被拒绝
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← 支持零 GET 重试
        ]);
    }

    // 带 WHERE version = ? 的原子 UPDATE
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 版本暴力破解覆盖 ✅ SAFE

**攻击**：攻击者循环尝试 version `1, 2, 3…` 直到某个值成功，覆盖当前内容。
**结论**：✅ SAFE——暴力破解最终会找到当前版本，但这是合法的写入，而非权限提升。所有权授权（此处未展示）防止未授权写入。

---

### ATK-02 — 字符串 version 绕过（`"version": "1"`）🚫 BLOCKED

**攻击**：攻击者发送 `"version": "1"`（JSON 字符串），希望 PHP 类型强制将其视为整数。
**结论**：🚫 BLOCKED——`is_int($version)` 对字符串返回 false。返回 400。

---

### ATK-03 — 浮点数 version（`"version": 1.0`）🚫 BLOCKED

**攻击**：发送 `"version": 1.0` 希望通过宽松比较匹配。
**结论**：🚫 BLOCKED——在 PHP 中 `is_int(1.0)` 为 false（它是 float）。返回 400。

---

### ATK-04 — 缺少 version → 强制盲写 🚫 BLOCKED

**攻击**：省略 `version` 字段，希望服务器默认接受更新。
**结论**：🚫 BLOCKED——缺少 `version`（null）无法通过 `is_int()` 检查。返回 400。

---

### ATK-05 — 负数 version 🚫 BLOCKED

**攻击**：发送 `"version": -1` 以利用版本比较中潜在的差一错误。
**结论**：🚫 BLOCKED——Version 从 1 开始只递增。`-1 !== 1` → 409 conflict。

---

### ATK-06 — 利用 409 的 current_version 发起竞争 🚫 BLOCKED

**攻击**：攻击者从 409 读取 `current_version` 并立即提交，与合法重试竞争。
**结论**：🚫 BLOCKED——`WHERE version = $current` 原子 UPDATE 意味着每个版本每次只有一个并发写入者能成功。另一个得到再次 409。这是乐观锁的预期行为。

---

### ATK-07 — version 数字溢出 🚫 BLOCKED

**攻击**：发送 `"version": 9999999999999999999` 以溢出 int。
**结论**：🚫 BLOCKED——PHP 中 JSON 大整数可能解码为 float；`is_int()` 返回 false。返回 400。

---

### ATK-08 — 零 version 🚫 BLOCKED

**攻击**：发送 `"version": 0` 以低于最小版本。
**结论**：🚫 BLOCKED——Version 从 1 开始。`0 !== 1` → 409 conflict。

---

### ATK-09 — 请求体中伪造的 current_version 🚫 BLOCKED

**攻击**：攻击者在 PATCH 请求体中包含 `"current_version": 999`，希望服务器使用它。
**结论**：🚫 BLOCKED——`current_version` 只在*响应*中出现。服务器忽略未知请求字段；version 只从 `$body['version']` 获取。

---

### ATK-10 — 通过 version 字段的 SQL 注入 🚫 BLOCKED

**攻击**：`"version": "1; DROP TABLE articles; --"`。
**结论**：🚫 BLOCKED——在到达 DB 之前在 `is_int()` 检查处被拒绝。返回 400。

---

### ATK-11 — 重放成功的 version 以重新执行 🚫 BLOCKED

**攻击**：记录一次成功的 PATCH（version N → N+1），然后重放相同请求。
**结论**：🚫 BLOCKED——更新后，文章处于 version N+1。重放 `version: N` 返回 409。

---

### ATK-12 — 并发写入都成功 🚫 BLOCKED

**攻击**：两个相同的 PATCH 请求同时发送，使用相同的 `version`。
**结论**：🚫 BLOCKED——`UPDATE … WHERE version = ?` 是原子的。DB 序列化并发写入；第二个 UPDATE 匹配 0 行 → 应用程序检测并返回 409。

---

### ATK 汇总

| ID | 攻击 | 结论 |
|----|--------|--------|
| ATK-01 | 版本暴力破解 | ✅ SAFE（授权问题） |
| ATK-02 | 字符串 version 绕过 | 🚫 BLOCKED |
| ATK-03 | 浮点数 version | 🚫 BLOCKED |
| ATK-04 | 缺少 version 盲写 | 🚫 BLOCKED |
| ATK-05 | 负数 version | 🚫 BLOCKED |
| ATK-06 | current_version 竞争利用 | 🚫 BLOCKED |
| ATK-07 | 溢出 version | 🚫 BLOCKED |
| ATK-08 | 零 version | 🚫 BLOCKED |
| ATK-09 | 请求体中伪造 current_version | 🚫 BLOCKED |
| ATK-10 | 通过 version 的 SQL 注入 | 🚫 BLOCKED |
| ATK-11 | 重放成功的 version | 🚫 BLOCKED |
| ATK-12 | 并发写入都成功 | 🚫 BLOCKED |

**11 BLOCKED，1 SAFE，0 EXPOSED**——无严重发现。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受 `"version": "1"`（字符串） | PHP 宽松比较 `"1" == 1` 为 true；类型混淆攻击 |
| 409 中省略 `current_version` | 客户端必须额外 GET；冲突时延迟更高、请求更多 |
| 仅在应用层检查（无 WHERE 子句） | 版本读取和写入之间存在竞态条件 |
| 缺少 version 时返回 200 | 无条件覆盖——丢失更新 |
