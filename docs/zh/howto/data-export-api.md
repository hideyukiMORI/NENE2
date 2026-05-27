# 操作指南：数据导出 API

> **FT 参考**：FT312（`NENE2-FT/exportlog`）——数据导出（GDPR 风格）：通过基于令牌的下载实现的异步 `pending→ready` 状态机，通过 `toPublicArray()` 排除 PII（password_hash 和 phone 从不出现在 GET 响应或导出载荷中），ARGON2ID 密码哈希，64 位十六进制导出令牌，过期导出返回 410 Gone，待处理时尝试下载返回 409，19 个测试 / 32 个断言全部通过。

本指南展示如何构建用户数据导出系统（GDPR 第 20 条可移植性），其中导出是异步的，通过令牌保护，且 PII 敏感字段从不泄露。

## 数据库结构

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 位十六进制字符
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON，status='ready' 时设置
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` 是用于下载 URL 的 64 字符十六进制字符串。`payload` 在处理导出之前为 null。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/users` | 注册用户 |
| `GET` | `/users/{id}` | 获取用户（排除 PII） |
| `POST` | `/users/{id}/export` | 请求数据导出 → 202 |
| `POST` | `/exports/{token}/process` | 处理导出（异步 worker） |
| `GET` | `/exports/{token}` | 下载已完成的导出 |

## PII 排除——toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone 和 password_hash 有意从公开视图中排除
        ];
    }
}
```

`GET /users/{id}` 响应调用 `toPublicArray()`——从不使用完整数组。`phone` 和 `password_hash` 存储但从不通过 API 返回。

导出载荷也应用相同的排除：导出从 `toPublicArray()`（或等效方法）构建，而非直接来自原始数据库行。

## 密码哈希——ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID 是推荐的现代算法（内存硬化，抵抗 GPU 攻击）。`PASSWORD_BCRYPT` 可以接受，但对 GPU 破解抵抗力较弱。

## 异步导出——pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → 创建 data_exports 行：status='pending'，token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → 构建载荷，设置 status='ready'

GET /exports/{token}  →  200 OK（下载）
  → 如果 status='ready' 则返回载荷
```

**导出令牌生成：**
```php
$token = bin2hex(random_bytes(32)); // 64 位十六进制字符
```

**处理处理器：**
```php
if ($export->status === 'ready') {
    return 200; // 已处理，幂等
}
if ($export->expiresAt < date('c')) {
    return 410; // 已过期——不处理
}
// 构建并存储载荷
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## 状态检查——409 和 410

```php
// 下载处理器
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| 状态 | 下载响应 |
|------|---------|
| `pending` | 409 Conflict |
| `ready`（未过期） | 200 OK 附载荷 |
| `ready`（已过期） | 410 Gone |

410 Gone 用于已过期的资源（GDPR：导出数据不应无限期保留）。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| GET 响应中包含 `password_hash` | 密码哈希暴露；支持离线破解 |
| 无认证情况下 GET 响应包含 `phone` | PII 泄露；任何知道用户 ID 的人都能获取电话号码 |
| 导出载荷包含 `password_hash` | GDPR 违规；导出是面向用户的数据可移植性文档 |
| 使用 `PASSWORD_MD5` 或 `PASSWORD_DEFAULT` | 弱密码哈希；升级到 ARGON2ID |
| 过期导出返回 404（而非 410） | 404 隐藏了"从未存在"和"已过期"的区别 |
| 待处理下载返回 200 | 客户端认为导出已就绪；收到空或损坏的载荷 |
| 短导出令牌（< 64 字符） | 可猜测的令牌；任何人都可以下载任何用户的导出 |
| 导出无 `expires_at` | 导出无限期保留；GDPR 合规问题 |
