# 个人数据导出

GDPR 风格的数据导出让用户能够下载所有个人数据。主要关注点是：从导出载荷中排除敏感字段、安全下载令牌以及过期时间强制执行。

## 核心组件

- **导出任务**：将用户与一个不透明下载令牌关联的记录，带有状态（pending → ready）和过期时间戳。
- **处理步骤**：工作端操作，构建载荷并将任务标记为 ready。
- **下载**：通过令牌获取载荷，在提供数据前检查过期。

## 数据库结构

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## 令牌生成

使用 `bin2hex(random_bytes(32))`——64 个十六进制字符，256 位熵。顺序 ID、时间戳或基于 MD5 的令牌是可猜测的，不得用于下载令牌。

```php
$token = bin2hex(random_bytes(32));
```

## 敏感字段排除

导出载荷中绝不能包含凭证或用户未明确同意导出的字段。在仓储层排除，而非在 HTTP 层：

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash 有意排除
            // phone 有意排除（PII 需要重新获取同意）
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

对公开个人资料端点应用相同的排除——`phone`、`password_hash` 以及任何内部字段也不应出现在 `GET /users/{id}` 响应中。

## 过期时间强制执行

在下载端点和处理端点**两处**都强制执行过期：

```php
// 在 downloadExport 中：
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// 在 processExport 中——关键：这里也要检查
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

如果不在 `processExport` 中检查，收到过期任务的工作者会将用户数据写入 DB，即使下载窗口已关闭，从而产生包含敏感载荷数据的孤立记录。

## 状态流

```
pending ──（调用 process，未过期）──▶ ready ──（调用 download）──▶ [提供载荷]
   │                                                │
   └──（调用 process，已过期）──▶ 410                └──（已过期）──▶ 410
```

## 下载：410 Gone 与 404 Not Found

- **404**：令牌不存在于数据库中。
- **410 Gone**：令牌存在但已过期。这是正确的状态——资源曾经存在，此后已被移除。客户端可以使用此信号提示用户请求新的导出。

## 设计决策

**为何使用单独的 `process` 步骤而非同步生成？**
导出载荷可能很大（数年的活动数据）。在 HTTP 处理器中同步生成会有超时风险并占用工作者。异步模式让用户可以请求后稍后检查。在本 FT 中，process 步骤以 API 的形式暴露，用于模拟工作者调用。

**为何使用令牌作为下载 URL 而非导出 ID？**
顺序整数 ID 存在 IDOR 漏洞——用户 1 可以通过递增 ID 下载用户 2 的导出。不透明随机令牌使下载 URL 不可猜测。

**`process` 应该是公开端点吗？**
在生产环境中，不应该。process 端点应只由内部工作者调用（通过 API 密钥、内部网络或队列）。在本 FT 中为可测试性而公开。令牌的熵提供了一定保护，但不能替代正确的工作者认证。
