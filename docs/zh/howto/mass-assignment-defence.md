# 操作指南：使用显式 DTO 的批量赋值防御

> **FT 参考**：FT256（`NENE2-FT/masslog`）——带显式 DTO 白名单的批量赋值防御模式
> **ATK**：FT256——破解者思维攻击测试（ATK-01 至 ATK-12）

演示如何使用只允许调用者设置特定字段的显式只读 DTO 来防止批量赋值漏洞。服务端控制的字段（`role`、`is_active`、`created_at`、`id`）被排除在 DTO 之外，并在数据仓库中硬编码。包含完整的破解者思维攻击评估。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/users` | 创建用户（role=user） |
| `GET`  | `/users` | 列出所有用户 |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` 是 DB 层面的安全网。应用程序在创建时始终向 `role` 写入 `'user'`，因此正常操作中该约束不会被触发——它防护的是 bug 或直接 DB 访问。

---

## 显式 DTO：字段白名单

```php
/**
 * 用于创建用户的显式 DTO——只接受用户输入中的 name 和 email。
 *
 * role 和 is_active 被故意排除：它们必须由服务端业务逻辑设置，
 * 绝不从请求体获取。这就是批量赋值防御。
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

DTO 只有两个字段——`name` 和 `email`。没有 `role`、`is_active`、`created_at` 或 `id` 字段。攻击者无法注入这些字段，因为构造函数根本不接受它们。

**为什么比黑名单更好**：

| 方法 | 安全模型 | 失效模式 |
|------|----------|----------|
| 显式白名单（DTO） | 默认拒绝未知字段 | 安全——新字段必须显式添加 |
| 黑名单（`unset($body['role'])`） | 阻止已知危险字段 | 不安全——新的敏感字段会被遗忘 |
| `array_intersect_key` | 过滤到已知键 | 可接受——如果键完整则与白名单等效 |

显式 DTO 安全失效：在模式中添加新的敏感列不会自动暴露它——开发者必须显式将其添加到 DTO 中。

---

## 控制器：显式字段提取

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // 只映射允许的字段——额外字段（role、is_active 等）被静默丢弃
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

控制器显式读取 `$body['name']` 和 `$body['email']`。`$body` 中的所有其他键被静默丢弃——它们从不被读取或传递到任何地方。

邮箱在创建 DTO 之前规范化为小写（`strtolower`），防止仅大小写不同的重复邮箱。

---

## 数据仓库：服务端控制字段

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role 和 is_active 硬编码
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // 硬编码，不来自 $input
        isActive:  true,      // 硬编码，不来自 $input
        createdAt: $now,
    );
}
```

`'user'` 和 `1` 是 INSERT 中的字面量值。用户输入无法影响 `role` 或 `is_active`。`CreateUserInput` DTO 类型签名在 PHP 类型层面强制了这一点。

---

## ATK——破解者思维攻击测试（FT256）

### ATK-01 — 角色提权：在请求体中注入 `role: "admin"`

**攻击**：在请求体中包含 `role` 以创建管理员用户。

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**观察结果**：`role` 不是 `CreateUserInput` 的字段。控制器只从 `$body` 读取 `name` 和 `email`。额外的键被静默丢弃。创建的用户有 `role = 'user'`。

**结论**：🚫 BLOCKED——显式 DTO 字段白名单防止权限提升。

---

### ATK-02 — 账户状态操控：注入 `is_active: false`

**攻击**：创建 `is_active = false` 的用户以创建禁用账户或测试字段是否可写。

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**观察结果**：`is_active` 不在 `CreateUserInput` 中。创建的用户有 `is_active = true`（在 INSERT 中硬编码）。

**结论**：🚫 BLOCKED——`is_active` 从不从请求中读取。

---

### ATK-03 — 时间戳操控：注入 `created_at`

**攻击**：将用户的创建时间戳往前回溯。

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**观察结果**：`created_at` 不在 `CreateUserInput` 中。数据仓库在写入时从 `DateTimeImmutable` 生成 `$now`。

**结论**：🚫 BLOCKED——审计时间戳由服务端生成，而非客户端提供。

---

### ATK-04 — ID 劫持：注入 `id: 9999`

**攻击**：预先指定主键以覆盖现有记录或占用已知 ID。

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**观察结果**：`id` 不在 `CreateUserInput` 中。INSERT 使用 `AUTOINCREMENT`——`id` 由 SQLite 分配，不来自任何用户提供的值。

**结论**：🚫 BLOCKED——主键分配始终在服务端进行。

---

### ATK-05 — 通过 name 或 email 的 SQL 注入

**攻击**：嵌入 SQL 元字符。

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**观察结果**：两个字段都作为参数化 `?` 占位符绑定到 INSERT 中。注入载荷以字面文本存储。

**结论**：🚫 BLOCKED——参数化查询防止 SQL 注入。

---

### ATK-06 — 邮箱大小写绕过：提交大写邮箱

**攻击**：将 `ADMIN@EXAMPLE.COM` 注册为与 `admin@example.com` 不同的用户。

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**观察结果**：控制器在传入 DTO 之前应用 `strtolower()`。`ADMIN@EXAMPLE.COM` 和 `admin@example.com` 都规范化为 `admin@example.com`。`UNIQUE` 约束防止第二次注册。

**结论**：🚫 BLOCKED——大小写规范化 + UNIQUE 约束防止重复账户。

---

### ATK-07 — 重复邮箱：两次注册相同地址

**攻击**：注册相同的邮箱地址以触发错误或创建重复账户。

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**观察结果**：第一个请求以 `201` 成功。第二个请求触发 SQLite `UNIQUE` 约束违反。当前实现未捕获此异常——它作为未处理错误传播。

**结论**：⚠️ EXPOSED——捕获唯一约束违反并返回结构化的 `409 Conflict` 或 `422 Unprocessable Entity` 响应。泄露原始 DB 错误是安全和 UX 问题。

---

### ATK-08 — name 或 email 中的 XSS 载荷

**攻击**：存储脚本标签。

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**观察结果**：内容原样存储并在 JSON 中原样返回。API 不对输出进行 HTML 编码。

**结论**：ACCEPTED BY DESIGN——JSON API 返回原始内容。渲染层在插入 HTML 之前必须进行消毒。

---

### ATK-09 — 缺少必填字段

**攻击**：省略 `name` 或 `email`。

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**观察结果**：每个都返回带有结构化 `errors` 数组的 `422 Unprocessable Entity`，按名称标识缺失字段。

**结论**：🚫 BLOCKED——对每个必填字段进行显式存在检查。

---

### ATK-10 — 类型混淆：以整数提交 name

**攻击**：以 JSON 数字发送 `name`。

```json
{"name": 12345, "email": "typed@example.com"}
```

**观察结果**：`is_string($body['name'])` 对整数值返回 `false`。请求以 `name is required` 的 `422` 返回。

**结论**：🚫 BLOCKED——`is_string()` 拒绝非字符串类型。

---

### ATK-11 — 超长 name 或 email

**攻击**：提交 10,000+ 字符的 name 或 email。

```json
{"name": "aaaa...aaaa（10000 字符）", "email": "x@example.com"}
```

**观察结果**：请求以 `201` 成功。未对 `name` 或 `email` 应用长度校验。SQLite 存储 TEXT 没有固有长度限制。

**结论**：⚠️ EXPOSED——添加长度校验（例如 `mb_strlen($name) > 255 → 422`）。依赖请求大小中间件作为外部限制。

---

### ATK-12 — 多个 role 值：以数组注入

**攻击**：以数组而非字符串提交 `role`。

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**观察结果**：`role` 根本不从 `$body` 读取。它是字符串、数组还是 null 对创建的用户没有影响。

**结论**：🚫 BLOCKED——DTO 完全排除 `role`；其类型无关紧要。

---

## ATK 汇总

| # | 攻击向量 | 结论 |
|---|----------|------|
| ATK-01 | 通过 `role: "admin"` 的角色提权 | 🚫 BLOCKED |
| ATK-02 | 通过 `is_active: false` 的账户状态操控 | 🚫 BLOCKED |
| ATK-03 | 通过 `created_at` 的时间戳回溯 | 🚫 BLOCKED |
| ATK-04 | 通过 `id: 9999` 的 ID 劫持 | 🚫 BLOCKED |
| ATK-05 | 通过 name/email 的 SQL 注入 | 🚫 BLOCKED |
| ATK-06 | 邮箱大小写绕过（`ADMIN@EXAMPLE.COM`） | 🚫 BLOCKED |
| ATK-07 | 重复邮箱（无优雅错误处理） | ⚠️ EXPOSED |
| ATK-08 | name 中的 XSS 载荷 | ACCEPTED BY DESIGN |
| ATK-09 | 缺少必填字段 | 🚫 BLOCKED |
| ATK-10 | 类型混淆（name 为整数） | 🚫 BLOCKED |
| ATK-11 | 超长 name 或 email（无长度限制） | ⚠️ EXPOSED |
| ATK-12 | role 为数组 | 🚫 BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-07** — 捕获 UNIQUE 约束违反；返回带用户友好消息的 `409 Conflict`
2. **ATK-11** — 为 `name` 和 `email` 添加 `mb_strlen` 长度校验

---

## 相关操作指南

- [`mass-assignment.md`](mass-assignment.md) — 批量赋值防御模式概述
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — 所有权范围查询以防止 IDOR
- [`rbac.md`](rbac.md) — 基于 JWT 声明的角色访问控制
- [`user-profile-management.md`](user-profile-management.md) — 带字段白名单的个人资料更新
