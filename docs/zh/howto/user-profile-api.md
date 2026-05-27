# 操作指南：用户档案 API

> **FT 参考**：FT275（`NENE2-FT/profilelog`）— 用户档案：每用户一个档案（UNIQUE user_id）、使用 FILTER_VALIDATE_EMAIL 验证邮件、字段长度限制（display_name 100 / bio 500 / avatar_url 2048）、仅 https 头像 URL、DatabaseConstraintException → 409、通过 X-User-Id 进行所有权验证，32 tests 全部 PASS。

演示 1:1 用户与档案关系：创建用户（邮件唯一）、创建/获取/更新其档案。档案字段有强制长度限制和 URL 安全约束。

---

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`user_id UNIQUE` 在 DB 层强制每用户只能有一个档案的不变量。

---

## 路由

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/users` | 创建用户（需要邮件，唯一） |
| `POST` | `/users/{userId}/profile` | 为用户创建档案 |
| `GET` | `/users/{userId}/profile` | 获取档案 |
| `PUT` | `/users/{userId}/profile` | 更新档案（仅所有者） |

---

## 邮件验证

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

邮件重复时，捕获 `DatabaseConstraintException` 并映射为 409：

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## 字段限制（UserProfile value object）

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

长度使用 `mb_strlen()` 检查（多字节安全）：

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## 仅 https 头像 URL

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // 只允许 https 以防止 javascript: 和 data: URI schemes
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` 在 `filter_var` 运行之前阻断 `javascript:`、`data:` 和 `http://`。

---

## 所有权验证

档案更新要求 `X-User-Id` 与档案所有者匹配：

```php
$actorId = $this->resolveActorId($request); // 从 X-User-Id 头部获取

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 不验证邮件格式 | 无效邮件被存储；后续发送静默失败 |
| `profiles` 中 `user_id` 无 UNIQUE | 可能产生重复档案；GET 返回不可预测的行 |
| 使用 `strlen()` 限制 display_name | 多字节字符（emoji、CJK）计数错误 |
| 允许 `http://` 头像 URL | 被动混合内容和潜在的点击劫持攻击面 |
| 允许 `javascript:` 或 `data:` URI | 若头像 URL 渲染为 `<a href>` 或 `<img src>` 则存在 XSS |
| 跳过 `DatabaseConstraintException` 捕获 | UNIQUE 违反变成 500 而非 409 |
| 允许任意用户更新任意档案 | IDOR——写入前始终检查操作者 = 所有者 |
