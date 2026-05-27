# 批量赋值防御

批量赋值是一种漏洞，攻击者在请求体中添加额外字段——例如 `role=admin` 或 `is_active=false`——而服务器在无意间将其持久化。

NENE2 没有 `create($body)` 魔法方法会让这种情况意外触发。即便如此，DTO 白名单模式才是正确且明确的防御手段。

## 漏洞描述

```php
// ❌ 危险：$body 直接传入 INSERT
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

攻击者发送：

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

由于从请求中读取了 `$body['role']`，攻击者在数据库中获得了 `role=admin`。

## 防御手段：显式 DTO 白名单

定义一个只包含用户允许提供的字段的 DTO：

```php
/**
 * 只接受来自用户输入的 name 和 email。
 * role 和 is_active 由服务端逻辑设置，绝不从请求中读取。
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

在控制器中，只将允许的字段映射到 DTO：

```php
// ✅ 额外字段（role、is_active、id、created_at）从不从 $body 中读取
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

在数据仓库中，直接使用 DTO 的属性：

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role 和 is_active 硬编码
    );
    // ...
}
```

即使攻击者发送了 `role=admin`，`$input` 也只有 `name` 和 `email`——额外字段永远不会到达 INSERT。

## 涵盖的攻击场景

| 字段 | 攻击意图 | 防御 |
|-------|---------------|---------|
| `role=admin` | 权限提升 | `role` 不在 `CreateUserInput` 中；在数据仓库中始终设为 `'user'` |
| `is_active=false` | 创建禁用账户或锁定用户 | `is_active` 不在 DTO 中；始终设为 `1` |
| `id=9999` | 覆盖主键 | `id` 不在 DTO 中；由 SQLite 自动分配 |
| `created_at=2000-01-01` | 伪造审计时间戳 | `created_at` 不在 DTO 中；始终设为当前时间 |

## 响应字段控制

防御延伸到响应：绝不直接返回 DB 行。显式映射要包含的内容：

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash 故意排除
    // deleted_at 故意排除
], 201);
```

测试敏感字段的缺失：

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## 受信任的内部服务

当内部服务需要创建管理员用户时（例如配置服务），使用独立的 DTO：

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // 仅允许内部调用者使用
        public bool $isActive,
    ) {}
}
```

只从已验证了调用者身份的代码路径调用该 DTO（例如机器 API 密钥、内部服务认证）。绝不暴露直接接受 `AdminCreateUserInput` 的公开 HTTP 端点。

## 列表响应使用 `createList()` 而非 `create()`

返回列表时，使用 `createList()` 而非 `create()`：

```php
// ✅ 顶级 JSON 数组
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ 顶级 JSON 对象
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` 期望 `array<string, mixed>`（一个对象）。直接将 `array_map()` 的输出传给 `create()` 会导致 PHPStan level 8 类型错误，因为 `array_map` 返回 `list<T>`。

## 代码审查清单

- [ ] 请求体字段在传给数据仓库之前已映射到 DTO
- [ ] DTO 只包含用户允许提供的字段
- [ ] 服务端控制的字段（`role`、`is_active`、时间戳、主键）在数据仓库中设置，不从 `$body` 读取
- [ ] 响应显式列出返回字段；无通配符 `SELECT *` 或直接行转 JSON 序列化
- [ ] 测试验证额外的请求字段被忽略，不影响持久化的值
