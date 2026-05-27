# 操作指南：滑动窗口限流器

## 概述

本指南介绍如何使用 NENE2 构建按用户、按端点的滑动窗口限流器。请求在滚动时间窗口内计数；一旦达到限制，进一步的请求将以 `429 Too Many Requests` 被拒绝。

**参考实现**：`../NENE2-FT/ratelog/`

---

## 数据库结构设计

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

`(user_id, endpoint, created_at)` 上的索引使 COUNT 查询在规模增长时保持快速。

---

## 路由表

| 方法 | 路径 | 认证 | 说明 |
|------|------|------|------|
| `POST` | `/rate/check` | 用户 | 记录请求；超出限制时返回 429 |
| `GET` | `/rate/status` | 用户 | 用户/端点的当前使用量 |
| `DELETE` | `/rate/reset/{userId}` | 管理员 | 重置用户的计数器 |

---

## 核心算法

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60 秒
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**滑动窗口**：每次 `check()` 都从当前时刻向前回溯 `WINDOW_SECONDS` 秒，因此旧事件自然超出作用域。

---

## 管理员重置与故障关闭模式

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // 故障关闭：未配置密钥时拦截所有管理员访问
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

重置用户的所有计数器（所有端点）：
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

重置特定端点：
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## 路径参数提取（不使用 Router::param()）

当已安装版本中没有 `Router::param()` 时，直接使用属性：

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## 校验

- `endpoint`：非空字符串，最长 128 个字符
- `X-User-Id`：`ctype_digit()` + 正整数
- 路径 `userId`：`ctype_digit()` + 正整数（失败 → 404）
- 管理员密钥：`hash_equals()` 比较（失败 → 403）

---

## HTTP 状态码

| 情况 | 状态码 |
|------|--------|
| 请求允许 | 200 |
| 状态已获取 | 200 |
| 计数器已重置 | 200 |
| 无 X-User-Id | 400 |
| 无请求体 | 400 |
| endpoint 为空或缺失 | 422 |
| endpoint 过长 | 422 |
| 无管理员密钥 | 403 |
| 管理员密钥错误 | 403 |
| 路径中 userId 无效 | 404 |
| 超出限流 | 429 |

---

## ATK 攻击模式覆盖

| ATK | 模式 | 防御措施 |
|-----|------|----------|
| ATK-01 | 缺少 X-User-Id | 400 带消息 |
| ATK-02 | 空 endpoint 字符串 | 422 校验 |
| ATK-03 | 129 字符 endpoint（DoS） | 长度限制 422 |
| ATK-04 | endpoint 中的 SQL 注入 | 参数化查询 |
| ATK-05 | 非管理员重置尝试 | 403 故障关闭 |
| ATK-06 | 错误管理员密钥 | 403 hash_equals() |
| ATK-07 | 路径中负数 userId | 404 |
| ATK-08 | 零 userId | 404 |
| ATK-09 | 非数字 userId（`abc`） | 404 ctype_digit |
| ATK-10 | 状态查询无 endpoint 参数 | 422 |
| ATK-11 | 无请求体的检查 | 400 |
| ATK-12 | 请求体缺少 endpoint 键 | 422 |

---

## 注意事项

- **并发**：滑动窗口存在小的 TOCTOU 窗口。对于高并发生产环境，考虑使用原子计数器（Redis INCR + EXPIRE）或数据库级别的锁定。
- **时钟偏差**：所有时间戳应使用 UTC，以避免夏令时或时区带来的意外。
- **存储增长**：旧事件会积累。添加定期清理任务：`DELETE FROM rate_events WHERE created_at < :cutoff`。
