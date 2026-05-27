# 操作指南：审计追踪——记录谁更改了什么

> **FT 参考**：FT268（`NENE2-FT/auditlog`）——只追加审计追踪：JWT 操作者提取，变更前/后载荷快照，不可变审计表，未认证审计读取漏洞
>
> **ATK 评估**：ATK-01 至 ATK-12 包含在本文档末尾。

本指南展示如何在 NENE2 应用程序中实现只追加审计追踪。审计追踪记录每次创建、更新和删除操作，包含操作者（来自 JWT 声明）、资源和载荷快照。这些记录是不可变的：API 从不暴露审计表的 UPDATE 或 DELETE 端点。

---

## 数据库结构

```sql
-- actor_id 和 resource_id 上没有外键：
-- 审计记录必须在其描述的主体被删除后依然存在。
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- 例如 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- 为最常见的查询模式添加索引
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

关键设计选择：
- **无外键约束**——审计记录比其主体存活更长。如果一个任务被删除，其审计历史必须保留。
- **设计上不可变**——永远不要为此表添加 UPDATE 或 DELETE SQL 路径。
- **`action` 作为类型化动词**——使用过去式动词（`created`、`updated`、`deleted`）使日志条目自描述。

---

## AuditEntry DTO 和 AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC，而非 occurred_at DESC：秒级精度的时间戳在同一秒内
            // 两次操作会得到相同的时间戳，导致排序顺序不可预测。
            // 自增 id 可靠地保留插入顺序。
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **使用 `ORDER BY id DESC` 而非 `occurred_at DESC`：** `occurred_at` 是秒级精度的。同一秒内的两次操作会得到相同的时间戳，使排序顺序不可预测。自增 `id` 可靠地保留插入顺序。

---

## 在处理器中记录审计

在处理器（UseCase 等价物）中记录审计事件，而非在 Repository 中。在 Repository 中记录会丢失业务上下文（"是什么操作触发了这个？"）。

### 创建——记录初始快照

```php
$task = $this->tasks->create($title, $body, $actorId);

// 审计：不要在载荷中包含 actor_id——它已经在审计记录本身中了。
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### 更新——记录变更前后以便查看差异

```php
$before = $this->tasks->findById($id);
// ... 所有权检查、验证 ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### 删除——删除前快照

```php
$task = $this->tasks->findById($id);
// ... 所有权检查 ...
$this->tasks->delete($id);

// 删除后记录——任务行已消失，但审计记录依然存在。
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## 从 JWT 声明获取操作者

始终从经过验证的 JWT 获取操作者，绝不从请求体获取。

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` 由 `BearerTokenMiddleware` 在验证令牌后设置。客户端无法在请求体中提供假的 `actor_id` 并让其被记录。

---

## 敏感字段排除

**绝不将密码、令牌或内部 ID 放入载荷。**

```php
// 错误——泄露敏感数据且冗余
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // 绝不包含
    'actor_id'      => $actorId,              // 冗余
]);

// 正确——只包含业务可见属性
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## 不可变审计 API——无写入端点

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST、PUT、DELETE 被故意省略
}
```

---

## 每次写入前检查所有权（以及审计前）

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// 返回 404 而非 403，避免向未授权操作者确认资源存在。
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// 只有在此之后：修改 + 审计
```

---

## 查询审计日志

```php
// 特定资源的历史
GET /audit/task/42

// 操作者的所有事件
GET /audit?actor_id=7

// 跨资源类型的所有删除操作
GET /audit?action=deleted

// 安全分页
GET /audit?limit=20&offset=40
```

---

## 安全考虑

| 风险 | 缓解措施 |
|---|---|
| 审计日志删除 | 无 DELETE 端点。表级别：如果可能，拒绝应用数据库用户的 DELETE 权限 |
| 操作者冒充 | 操作者始终来自 `nene2.auth.claims`，绝不来自请求体 |
| 敏感载荷 | 明确从载荷中排除密码、令牌、内部密钥 |
| IDOR（跨用户审计读取） | 将 `GET /audit` 限制为管理员角色（结合 RBAC）；或按请求者的 actor_id 过滤 |
| 时序攻击/用户枚举 | 使用真实预计算的 Argon2id 哈希作为虚拟值，而非格式错误的字符串 |
| `LIMIT -1` DoS | 截断：`max(1, min((int) $limit, 100))` |

---

## 虚拟哈希必须是真实的 Argon2id 哈希

格式错误的虚拟哈希会导致 `password_verify()` 立即返回 false（不运行 KDF），产生约 20,000 倍的时序差异，让攻击者可以枚举有效的电子邮件地址。

```php
// 错误——KDF 被跳过，在约 0.001ms 内返回 false
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// 正确——真实预计算的哈希——KDF 以完整代价运行（约 180ms）
// 生成一次：password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> 此虚拟哈希模式首次记录于 [password-hashing.md](password-hashing.md)。
> **同样的原则适用于在可能缺失的用户上调用 `password_verify()` 的所有地方。**

---

## ATK 评估（FT268）

针对 `NENE2-FT/auditlog` 的破解者视角攻击测试。攻击面：JWT 认证的任务 CRUD + 未认证的审计日志读取。

### ATK-01 — JWT None 算法攻击 🚫 BLOCKED

**攻击**：伪造带有 `"alg":"none"` 且无签名的 JWT，包含任意 `sub` 声明。
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signature: （空）
```
**结果**：`LocalBearerTokenVerifier` 使用配置的密钥通过 HMAC-HS256 进行验证。没有有效签名的令牌会被拒绝——`alg:none` 不被接受。→ **401 Unauthorized**

---

### ATK-02 — JWT 签名篡改 🚫 BLOCKED

**攻击**：获取有效的 JWT，将 `sub` 字段修改为另一个用户的 ID（例如，`1` → `2`），不重新签名而重新编码。
**结果**：HMAC-HS256 签名不再匹配修改后的载荷。`LocalBearerTokenVerifier` 拒绝令牌。→ **401 Unauthorized**

---

### ATK-03 — JWT 过期令牌重放 🚫 BLOCKED

**攻击**：在 JWT 的 `exp` 时间戳过期后重放已捕获的 JWT。
**结果**：`BearerTokenMiddleware` / `LocalBearerTokenVerifier` 检查 `exp`。过期令牌被拒绝。→ **401 Unauthorized**

---

### ATK-04 — IDOR：通过 ID 访问其他用户的任务 ✅ BLOCKED

**攻击**：以用户 A（sub=1）认证，然后调用 `PUT /tasks/3`，其中任务 3 属于用户 B（sub=2）。
**结果**：任务路由处理器读取 `task->actorId` 并与 JWT 声明中的 `actorId` 比较。不匹配返回 → **404 Not Found**（资源存在不向攻击者确认）。

---

### ATK-05 — IDOR：删除其他用户的任务 ✅ BLOCKED

**攻击**：以用户 A 认证，调用 `DELETE /tasks/7`，其中任务 7 属于用户 B。
**结果**：与 ATK-04 相同的所有权保护。`task->actorId !== $actorId` → **404 Not Found**。

---

### ATK-06 — 通过请求体注入操作者 ID ✅ BLOCKED

**攻击**：`POST /tasks` 请求体为 `{"title":"Injected","actor_id":999}`。
**结果**：控制器完全忽略 `body['actor_id']`。审计记录使用来自 `nene2.auth.claims['sub']`（JWT）的 `actorId`。任务在已认证的操作者下创建——`actor_id:999` 没有任何效果。

---

### ATK-07 — 未认证的审计日志读取 ⚠️ EXPOSED

**攻击**：`GET /audit` 不带 Authorization 请求头。
**结果**：审计日志读取端点（`GET /audit`、`GET /audit/{type}/{id}`）**不受 `BearerTokenMiddleware` 保护**。中间件只排除 `/auth/login`；然而，审计路由注册器在没有要求认证的情况下附加路由。任何未认证的调用者都可以读取所有操作者和所有资源的完整审计历史。

**影响**：完全披露：谁做了什么，何时，对哪个资源，包括变更前后的载荷快照。对于多租户应用，这是一个严重的信息泄露。

**建议**：将审计端点限制为管理员范围的 JWT（例如，`claims['role'] === 'admin'`），或至少要求任何有效的 JWT。将审计前缀添加到 `BearerTokenMiddleware` 保护的路由中。

---

### ATK-08 — 通过 ?actor_id 进行跨操作者审计枚举 ⚠️ EXPOSED

**攻击**：`GET /audit?actor_id=2`（或枚举 1..N）——读取任意 actor_id 的所有审计条目。
**结果**：对 `actor_id` 过滤器没有授权检查。攻击者枚举所有用户 ID 并检索其完整审计历史。与 ATK-07 链接（未认证访问）。
**建议**：如果审计仅限于已认证用户（而非管理员），按已认证用户的 `sub` 过滤——调用者无法查询其他操作者的日志。管理员可查看所有内容。

---

### ATK-09 — 审计搜索参数中的 SQL 注入 🚫 BLOCKED

**攻击**：`GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**结果**：`$action` 和 `$resourceType` 在 SQL 查询中作为 `?` 参数绑定。没有字符串插值。SQLite 接收 `WHERE action = ?`，注入的字符串作为值——这只是返回 0 行。表数据安全。→ **200 OK（空）**

---

### ATK-10 — Limit -1 / 超大 Limit DoS ✅ BLOCKED

**攻击**：`GET /audit?limit=-1` 或 `GET /audit?limit=99999`。
**结果**：`max(1, min((int) ($q['limit'] ?? 50), 100))` 截断到 `[1, 100]`。负数和超大限制被静默截断。→ **200 OK（最多 100 条）**

---

### ATK-11 — 登录暴力破解（无速率限制） ⚠️ EXPOSED

**攻击**：对相同邮箱和不同密码快速顺序发送 `POST /auth/login` 请求。
**结果**：无速率限制、无锁定、无 CAPTCHA。攻击者可以无限迭代密码。Argon2id KDF 使每次尝试约需 180ms，使强密码的暴力破解不切实际，但弱密码仍可行。
**建议**：在 `/auth/login` 上添加 `ThrottleMiddleware`（例如，每个 IP 5 次尝试/15 分钟）。记录失败尝试的 request_id 以便监控。

---

### ATK-12 — 任意状态值注入 ⚠️ EXPOSED

**攻击**：`PUT /tasks/1` 请求体为 `{"status":"<script>alert(1)</script>"}` 或 `{"status":"admin_override"}`。
**结果**：处理器接受任何非空字符串作为 `status`。仓库按原样写入。任务被更新为 `status="<script>alert(1)</script>"`。没有枚举验证，没有白名单。
**影响**：如果状态在浏览器中未经转义就被渲染，则存在存储型 XSS。如果业务逻辑假设状态在 `{open, closed, in_progress}` 中，则域模型被破坏。
**建议**：针对白名单或 PHP BackedEnum 验证状态：
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### ATK 总结

| ID | 攻击 | 结果 |
|----|--------|--------|
| ATK-01 | JWT `alg:none` | 🚫 BLOCKED |
| ATK-02 | JWT 签名篡改 | 🚫 BLOCKED |
| ATK-03 | 过期 JWT 重放 | 🚫 BLOCKED |
| ATK-04 | IDOR：访问其他用户的任务 | ✅ BLOCKED |
| ATK-05 | IDOR：删除其他用户的任务 | ✅ BLOCKED |
| ATK-06 | 通过请求体注入操作者 ID | ✅ BLOCKED |
| ATK-07 | 未认证的审计日志读取 | ⚠️ EXPOSED |
| ATK-08 | 跨操作者审计枚举 | ⚠️ EXPOSED |
| ATK-09 | 审计搜索中的 SQL 注入 | 🚫 BLOCKED |
| ATK-10 | Limit -1 / 超大 Limit DoS | ✅ BLOCKED |
| ATK-11 | 登录暴力破解（无速率限制） | ⚠️ EXPOSED |
| ATK-12 | 任意状态值注入 | ⚠️ EXPOSED |

**9 项 BLOCKED / SAFE，4 项 EXPOSED**（ATK-07、08 来自同一个未认证审计读取漏洞）。

关键发现是 **ATK-07**：审计日志端点没有认证保护，向任何未认证的调用者暴露完整的操作者活动历史。ATK-12（状态白名单）和 ATK-11（速率限制）是标准的安全加固缺口。没有发现 SQL 注入或 JWT 伪造向量。
