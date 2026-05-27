# 用户档案管理

存储和更新面向用户的档案数据：显示名称、个人简介和头像 URL。档案创建与用户创建分离——先有用户，然后创建档案并就地更新。

## 概述

档案管理 API 涉及：
- **创建用户** — 基于邮件的用户注册（每用户一个档案）
- **创建档案** — 初始档案设置（防幂等：已存在则返回 409）
- **获取档案** — 获取当前档案数据
- **更新档案** — 替换档案字段（强制所有权）

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

`user_id` 上的 `UNIQUE` 在 DB 层强制每用户只能有一个档案。

## 处理重复邮件

捕获 `DatabaseConstraintException` 返回 409，而非泄露 500 错误：

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

不捕获此异常时，重复邮件会导致未处理的异常，向客户端暴露内部错误详情。

## 头像 URL 验证

只允许 `https://` URL，防止 `javascript:`、`data:`、`file://` 和 `http://` scheme：

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // 只允许 https——阻断 javascript:、data:、file://、ftp://、http://
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

空字符串允许（未设置头像）。`MAX_AVATAR_URL_LENGTH = 2048` 的限制防止存储滥用。

## 字段长度限制

在 value object 上将限制定义为常量，作为单一事实来源：

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

使用 `mb_strlen()` 而非 `strlen()` 以保证多字节（UTF-8）的正确性。

## 所有权检查

`PUT /users/{userId}/profile` 端点使用 `X-User-Id` 头部标识请求者。在生产环境中，用 JWT 声明替换：

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// 在处理器中：
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

非数字或缺失的头部解析为 `0`，永远不会匹配真实的用户 ID → 403。

## 防止重复档案

插入前检查是否已有档案，有则返回 409：

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

这可以防止第二次 `POST /users/{userId}/profile` 静默覆盖已有档案。

## 安全属性

| 属性 | 实现方式 |
|---|---|
| 重复邮件 | 捕获 `DatabaseConstraintException` → 409（不泄露堆栈跟踪） |
| avatar_url scheme | `str_starts_with('https://')` 阻断所有非 https scheme |
| avatar_url 长度 | `MAX_AVATAR_URL_LENGTH = 2048` |
| bio 长度 | `MAX_BIO_LENGTH = 500`，使用 `mb_strlen()` |
| 所有权 | `X-User-Id` 头部（生产环境中用 JWT 声明替换） |
| 每用户一个档案 | `UNIQUE (user_id)` DB 约束 + 处理器中的 409 检查 |

## 路由概览

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/users` | 注册用户（邮件，重复时 409） |
| `POST` | `/users/{userId}/profile` | 创建档案（已存在则 409） |
| `GET` | `/users/{userId}/profile` | 获取档案 |
| `PUT` | `/users/{userId}/profile` | 更新档案（需要 `X-User-Id` 头部） |
