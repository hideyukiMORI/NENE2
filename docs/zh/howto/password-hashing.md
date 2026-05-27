# 操作指南：密码哈希

使用 PHP 原生的 `password_hash()` / `password_verify()` 配合 NENE2 安全存储和验证密码。

---

## 快速入门

```php
// 注册——存储前哈希
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// 登录——恒定时间验证
if (!password_verify($inputPassword, $user->passwordHash)) {
    // 返回 401
}
```

---

## 算法：始终使用 `PASSWORD_ARGON2ID`

`PASSWORD_DEFAULT` 在 PHP 8.4 中仍是 `bcrypt`。Argon2id 是内存密集型的，能抵抗 GPU/ASIC 攻击。

```php
// ❌ PASSWORD_DEFAULT = bcrypt — 更容易受到 GPU 暴力破解
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — 内存密集型，推荐用于新项目
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id 需要 PHP 7.3+。NENE2 要求 PHP 8.4，因此始终可用。

---

## 检测 UNIQUE 违规：`DatabaseConstraintException`

NENE2 的 `PdoDatabaseQueryExecutor` 在重新抛出之前会将所有约束违规（UNIQUE、FK、NOT NULL）包装成 `DatabaseConstraintException`。直接捕获 `\PDOException` **不起作用**。

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ 永远不会到达这里——PDOException 已被包装
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ 捕获 NENE2 包装类
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` 是稳定公开 API 的一部分（ADR 0009）。

完整仓储模式：

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    /** @throws DuplicateEmailException */
    public function create(string $email, string $passwordHash): User
    {
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $id  = $this->executor->insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, $passwordHash, $now],
            );

            return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
        } catch (DatabaseConstraintException) {
            throw new DuplicateEmailException($email);
        }
    }
}
```

---

## 用户枚举防护（时序攻击）

如果在邮箱未找到时立即返回 401，时序差异就会揭示邮箱是否存在——未找到的响应立即返回，而密码错误的响应需要完整的 Argon2id 计算时间。

```php
// ❌ 时序泄露——未找到时明显更快
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ 始终运行 password_verify——无论用户是否存在，时间恒定
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

虚拟哈希**必须**是以 `$argon2id$` 开头的有效 Argon2id 格式字符串。如果不是，`password_verify()` 会短路并立即返回 `false`，重新引入时序泄露。

---

## `password_verify()` 是算法无关的

`password_verify()` 读取哈希前缀以确定算法。从 bcrypt 迁移到 Argon2id 时无需更改验证代码。

```php
// 对 bcrypt 和 Argon2id 哈希都适用
$result = password_verify($plaintext, $storedHash); // 始终正确
```

在成功登录时使用 `password_needs_rehash()` 透明地升级旧哈希：

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // 继续处理已认证用户
}
```

---

## 绝不在响应中包含 `password_hash`

`toArray()` 或类似的辅助方法可能包含每一列。只明确列出你打算返回的字段。

```php
// ❌ 如果 $user 有 toArray() 方法，可能泄露 password_hash
return $this->json->create($user->toArray(), 201);

// ✅ 明确字段列表——password_hash 永不出现
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## `RouteRegistrar::register()` 命名冲突

NENE2 的 `RouteRegistrar` 契约要求一个公开的 `register(Router $router)` 方法。**不要**将路由处理器命名为 `register()`——PHP 会拒绝重复的方法名。

```php
// ❌ 致命错误：无法重新声明 RouteRegistrar::register()
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ 使用不同的处理器名称
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## 代码审查清单

- [ ] 使用 `password_hash()` 配合 `PASSWORD_ARGON2ID`（不是 MD5、SHA-1、bcrypt 或 `PASSWORD_DEFAULT`）
- [ ] 使用 `password_verify()` 进行比较（不是 `===`、`hash_equals()` 或自定义比较）
- [ ] 用户未找到时也运行 `password_verify()`（虚拟哈希模式）
- [ ] 捕获 `DatabaseConstraintException` 以检测重复邮箱/用户名
- [ ] 从所有 API 响应中排除 `password_hash` / `password` 字段
- [ ] 登录对未知邮箱返回 401（而非 404）——绝不透露邮箱是否存在
- [ ] 明文密码不写入日志
