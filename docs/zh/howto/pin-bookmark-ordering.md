# 操作指南：固定/书签与排序

> **FT 参考**：FT327（`NENE2-FT/pinlog`）——每用户文章固定，带顺序位置、最大固定数量限制、删除时无间隙重新压缩、通过 PUT 重排序、用户隔离、VULN 评估，19 个测试 / 26 个断言全部通过。

本指南展示如何构建固定文章功能，用户可以维护一个最多 10 个书签的有序列表，并支持拖拽重排。

## 数据库结构

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST`  | `/pins` | 固定文章（幂等） |
| `DELETE`| `/pins/{articleId}` | 取消固定文章 |
| `GET`   | `/pins` | 按顺序列出用户的固定 |
| `PUT`   | `/pins/order` | 重排序固定 |

所有端点都需要 `X-User-Id` 请求头。缺失 → 401。

## 固定文章

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// 幂等——固定同一文章两次
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  （已固定，无变化）
```

### 数量限制

```php
// 已有 10 个固定
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### 错误情况

```php
// 无认证
POST /pins  {"article_id": 1}        → 401
// 缺少 article_id
POST /pins  X-User-Id: 1  {}         → 422
// 不存在的文章
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## 取消固定

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // 已删除
```

### 删除后位置压缩

删除固定后重新压缩位置——无间隙：

```
删除前：[1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
删除后：[1→Art1, 2→Art3]   // 位置 2 现在是 Art3
```

```php
// 取消固定后，间隙被填补
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // 位置 3 → 2
  ], "count": 2}
```

## 列出固定

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// 空列表
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

结果按 `position ASC` 排序。用户 2 永远看不到用户 1 的固定。

## 重排序

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// 未知 article_id（未固定）
{"article_ids": [1, 99]}  → 422

// 无 X-User-Id
PUT /pins/order  {"article_ids": [1]}  → 401
// 缺少 body
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## 漏洞评估

### V-01 — 取消固定时的 IDOR ✅ SAFE

**风险**：用户 2 通过猜测文章 ID 取消固定用户 1 的文章。
**结论**：SAFE——DELETE 查询包含 `WHERE user_id = $authUserId AND article_id = $articleId`。跨用户删除找到 0 行 → 404。

### V-02 — 重排序时的 IDOR ✅ SAFE

**风险**：用户 2 重排序用户 1 的固定列表。
**结论**：SAFE——重排序校验所有 `article_ids` 都在已认证用户的固定列表中。外部 ID 返回 422。

### V-03 — 固定数量限制绕过 ✅ SAFE

**风险**：攻击者提交并发固定请求以超过 10 个固定的限制。
**结论**：SAFE——`UNIQUE(user_id, article_id)` 防止重复。插入前检查固定数量。并发插入竞争到唯一约束。

### V-04 — 固定不存在的文章 ✅ SAFE

**风险**：攻击者固定 `article_id=999999` 以插入悬空的外键引用。
**结论**：SAFE——插入前进行存在性检查。不存在的文章返回 404。

### V-05 — 固定其他用户的文章 ✅ SAFE

**风险**：跨用户固定（用户 2 通过操纵 `X-User-Id` 以用户 1 的名义固定）。
**结论**：SAFE——在本 FT 中 `X-User-Id` 是认证令牌。在生产环境中，使用签名 JWT/会话——永不直接信任客户端提供的用户 ID 请求头。

### V-06 — 删除后位置间隙揭示排序 ✅ SAFE

**风险**：位置中的间隙（`1, 3`）揭示发生了删除；攻击者推断删除历史。
**结论**：SAFE——删除时立即压缩位置。外部观察者无法检测删除顺序。

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|---------------|---------|
| V-01 | 取消固定时的 IDOR | ✅ SAFE |
| V-02 | 重排序时的 IDOR | ✅ SAFE |
| V-03 | 固定数量限制绕过 | ✅ SAFE |
| V-04 | 固定不存在的文章 | ✅ SAFE |
| V-05 | 跨用户固定 | ✅ SAFE |
| V-06 | 间隙揭示删除历史 | ✅ SAFE |

**6 SAFE，0 EXPOSED**——无重大发现。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 无最大固定数量限制 | 无界列表降低查询性能和用户体验 |
| 删除后留有位置间隙 | 按位置排序的客户端出错；需要客户端重新编号 |
| 固定时跳过文章存在性检查 | 悬空引用使渲染固定列表的客户端感到困惑 |
| 在生产环境信任 `X-User-Id` 请求头 | 任何客户端都可以设置它；使用签名认证（JWT、会话） |
| 无 `UNIQUE(user_id, article_id)` | 重复固定导致计数膨胀并使重排序逻辑混乱 |
