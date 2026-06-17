# 操作指南：内容审批工作流

> **FT 参考**：FT248（`NENE2-FT/flowlog`）——内容审批工作流 API
> **ATK**：FT248——破解者思维攻击测试（ATK-01 至 ATK-12）

演示一个帖子发布生命周期，其中 `PostStatus` `BackedEnum` 通过 `canTransitionTo()` 管理状态转换图，无效转换抛出 `InvalidTransitionException → 409`，拒绝时携带可选原因。包含完整的破解者思维攻击评估。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/posts` | 创建帖子（始终以 `draft` 状态开始） |
| `GET` | `/posts` | 列出帖子（分页，可按状态过滤） |
| `GET` | `/posts/{id}` | 获取单个帖子 |
| `POST` | `/posts/{id}/submit` | 状态转换：`draft → submitted` |
| `POST` | `/posts/{id}/approve` | 状态转换：`submitted → approved` |
| `POST` | `/posts/{id}/reject` | 状态转换：`submitted → rejected`（可选原因） |

> **静态动作路由优先于参数化路由**：`/posts/{id}/submit`、`/approve`、`/reject` 在 `/posts/{id}` 之前注册，这样字面子路径不会被参数化段捕获。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` 有数据库层面的 `CHECK` 约束作为安全网；应用在任何写入前通过 `PostStatus::canTransitionTo()` 进行校验。`reject_reason` 可为 null——仅在拒绝时设置。

---

## 带 `canTransitionTo()` 的 `PostStatus` BackedEnum

状态转换图由枚举本身管理：

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // 终态
        };
    }
}
```

转换图：
```
draft → submitted → approved（终态）
                 → rejected（终态）
```

`Approved` 和 `Rejected` 是终态——不允许进一步转换。尝试批准已批准的帖子会抛出 `InvalidTransitionException`。

---

## 仓库转换方法

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

`transition()` 方法由提交、批准和拒绝共用——每个处理器用不同的 `$targetStatus` 调用它。`reject_reason` 在批准/提交时为 `null`，拒绝时可选提供。

---

## 使用 `PostStatus::tryFrom()` 进行状态过滤

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` 对未知字符串值返回 `null` 而不是抛出异常。显式的 `null` 检查产生结构化的 `422`，并附有列出有效值的可读错误消息。

---

## 带可选原因的拒绝

`POST /posts/{id}/reject` 接受可选的 `reason` 字段：

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

空请求体 `{}` 或缺少 `reason` 字段都会产生 `null`。只含空白字符的原因字符串也会通过 `trim()` 规范化为 `null`。原因存储在可为 null 的 `reject_reason` 列中。

---

## ATK——破解者思维攻击测试（FT248）

### ATK-01 — 无认证：任何人都可以批准或拒绝任何帖子

**攻击**：不提供任何凭据批准或拒绝帖子。

```bash
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/reject
```

**观察结果**：两者都以 `200 OK` 成功。任何调用者都可以推动任何帖子通过任何允许的转换。

**结论**：**EXPOSED**——添加认证和基于角色的授权。只有指定的审核人员才能批准/拒绝。提交应要求帖子作者通过认证。

---

### ATK-02 — 无效状态转换：批准草稿

**攻击**：尝试批准仍处于 `draft` 状态的帖子。

```bash
curl -X POST http://localhost:8200/posts/1/approve
# 帖子 1 处于 draft 状态
```

**观察结果**：`canTransitionTo(Approved)` 对 `Draft` 返回 `false` → `InvalidTransitionException` → `409 Conflict`，响应中包含来源/目标上下文。

**结论**：**BLOCKED**——枚举管理的转换图防止非法状态跳转。

---

### ATK-03 — 双重批准：对已批准的帖子再次批准

**攻击**：对帖子进行第二次批准。

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/approve  # 第二次批准
```

**观察结果**：第三个请求：从 `Approved` 调用 `canTransitionTo(Approved)` → `false` → `409 Conflict`。帖子保持 `Approved` 状态。

**结论**：**BLOCKED**——`Approved` 是终态；枚举明确对终态的所有转换返回 `false`。

---

### ATK-04 — 通过 title 或 body 进行 SQL 注入

**攻击**：嵌入 SQL 元字符。

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**观察结果**：值通过参数化的 `?` 占位符绑定。注入载荷作为字面文本存储。

**结论**：**BLOCKED**——参数化查询防止 SQL 注入。

---

### ATK-05 — 无效状态过滤值

**攻击**：向列表端点传递未知状态。

```
GET /posts?status=hacked
GET /posts?status=published
```

**观察结果**：`PostStatus::tryFrom('hacked')` 返回 `null` → `ValidationException` → `422 Unprocessable Entity`，附有有效状态列表。

**结论**：**BLOCKED**——`BackedEnum::tryFrom()` + 显式 null 检查拒绝未知状态值。

---

### ATK-06 — 作者冒充

**攻击**：创建帖子时声称是特权作者。

```json
{"title": "Official announcement", "author": "admin"}
```

**观察结果**：`201 Created`——`author` 字段从请求体中直接获取，无需验证。接受任意字符串。

**结论**：**EXPOSED**——`author` 是用户提供的，没有密码学绑定。在生产环境中，从已认证的会话/令牌中获取 `author`，绝不从请求体中获取。

---

### ATK-07 — 创建时的批量赋值：注入 `status`

**攻击**：在创建时直接将 `status` 设置为 `approved`。

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**观察结果**：`createPost()` 忽略请求体中的任何 `status` 字段——它始终插入 `PostStatus::Draft->value`。额外的键被静默丢弃。

**结论**：**BLOCKED**——控制器使用硬编码的 `PostStatus::Draft->value` 构建 INSERT；请求体字段无法覆盖它。

---

### ATK-08 — title、body 或 author 中的 XSS 载荷

**攻击**：存储脚本标签。

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**观察结果**：内容按原样存储，并在 JSON 中原样返回。API 不对输出进行 HTML 编码。

**结论**：**设计接受**——JSON API 返回原始内容。渲染层在插入 HTML 前必须进行净化。

---

### ATK-09 — 非数字帖子 ID

**攻击**：使用字符串或浮点数作为 `{id}`。

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**观察结果**：`(int) 'abc'` = `0`，`(int) '1.5'` = `1`。
- `abc` → `findById(0)` → 无记录 → `PostNotFoundException` → `404 Not Found`。
- `1.5` → `findById(1)` → 如果帖子 1 存在，则触发其转换。

**结论**：**部分拦截**——非数字字符串映射为 404。浮点字符串被静默截断。添加 `ctype_digit()` 进行严格的 ID 校验。

---

### ATK-10 — 空 title 或空 author

**攻击**：提交空白字段。

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**观察结果**：`trim($body['title']) === ''` 和 `trim($body['author']) === ''` 检查触发 → `ValidationException` → `422`。

**结论**：**BLOCKED**——trim + 空字符串检查同时覆盖空值和纯空白值。

---

### ATK-11 — 拒绝时不提供原因

**攻击**：使用空请求体或无 `reason` 字段拒绝。

```bash
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject -d '{}'
curl -X POST http://localhost:8200/posts/1/reject -d '{"reason": ""}'
```

**观察结果**：三种情况都产生 `null` 作为 `reject_reason`。不提供原因的拒绝是被接受的——该列可为 null。

**结论**：**设计接受**——`reject_reason` 是可选的。对于要求必填拒绝原因的生产工作流，添加 `if ($reason === null) → 422`。

---

### ATK-12 — 对已拒绝的帖子再次拒绝（双重拒绝）

**攻击**：尝试拒绝已经被拒绝的帖子。

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject  # 第二次拒绝
```

**观察结果**：从 `Rejected` 调用 `canTransitionTo(Rejected)` → `false` → `409 Conflict`。

**结论**：**BLOCKED**——`Rejected` 是终态；枚举明确对终态的所有转换返回 `false`。

---

## ATK 总结

| # | 攻击向量 | 结论 |
|---|---------|------|
| ATK-01 | 批准/拒绝无认证 | EXPOSED |
| ATK-02 | 无效转换（批准草稿） | BLOCKED |
| ATK-03 | 双重批准 | BLOCKED |
| ATK-04 | 通过 title/body 进行 SQL 注入 | BLOCKED |
| ATK-05 | 无效状态过滤值 | BLOCKED |
| ATK-06 | 作者冒充 | EXPOSED |
| ATK-07 | 创建时批量赋值 status | BLOCKED |
| ATK-08 | 内容中的 XSS 载荷 | 设计接受 |
| ATK-09 | 非数字帖子 ID | 部分拦截 |
| ATK-10 | 空 title 或空 author | BLOCKED |
| ATK-11 | 拒绝时无原因（可选） | 设计接受 |
| ATK-12 | 双重拒绝 | BLOCKED |

**生产前需要修复的真实漏洞**：
1. **ATK-01** ——添加认证和基于角色的授权（审核人员角色用于批准/拒绝）
2. **ATK-06** ——从已验证的身份中获取 `author`，绝不从请求体中获取
3. **ATK-09** ——为 ID 路径参数添加 `ctype_digit()` 保护

---

## 相关操作指南

- [`state-machine-audit-log.md`](state-machine-audit-log.md) ——带审计历史和 InvalidTransitionException 的状态转换
- [`approval-workflow.md`](approval-workflow.md) ——多个审批人的审批请求
- [`step-workflow-approval.md`](step-workflow-approval.md) ——带有序步骤的多步骤工作流
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) ——草稿/发布生命周期模式
