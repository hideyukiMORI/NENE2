# 操作指南：通知收件箱 API

> **FT 参考**：FT271（`NENE2-FT/notificationlog`）——通知收件箱：类型允许列表的通知创建，每用户 IDOR 保护（404 而非 403），管理员故障关闭模式，批量标记为已读，is_read 幂等性，带 PDO::PARAM_INT 绑定的分页钳制，31 个测试 / 98 个断言全部通过。
>
> 同样在 FT222（`NENE2-FT/notificationlog`）中验证——对相同模式的 VULN 评估。

本指南展示如何使用 NENE2 构建带类型允许列表推送通知、每用户 IDOR 保护和批量标记为已读的通知收件箱系统。

## 功能特性

- 带类型允许列表的仅管理员通知创建
- 每用户 IDOR 保护：用户只能看到自己的通知（未授权访问时返回 404）
- 带所有权验证的单个和批量标记为已读
- 每次列表查询都返回未读数
- 可选的仅未读过滤器和分页
- 管理员故障关闭

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

没有单独的 `users` 表——API 信任 `X-User-Id` 请求头（生产环境请替换为真实认证）。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/notifications` | 管理员 | 为用户创建通知 |
| `GET` | `/users/{userId}/notifications` | 本人 / 管理员 | 列出通知 |
| `POST` | `/notifications/{id}/read` | 本人 / 管理员 | 将单条通知标记为已读 |
| `POST` | `/users/{userId}/notifications/read-all` | 本人 / 管理员 | 全部标记为已读 |

## 类型允许列表

自由格式的类型字符串被拒绝，以防止注入和枚举攻击：

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

路由处理器在任何 DB 访问之前进行校验：

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## IDOR 保护

用户只能读取自己的通知。未授权访问时返回 404（而非 403）以防止用户 ID 枚举：

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

标记为已读也在操作前验证所有权：

```php
// POST /notifications/{id}/read 处理器
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR：只有所有者或管理员可以标记为已读
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## 管理员故障关闭

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // 故障关闭：未配置密钥时不允许管理员
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## 分页

`limit` 和 `offset` 在数据仓库中被钳制——绝不直接信任客户端提供的值：

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

PDO 整数绑定防止 LIMIT / OFFSET 的 SQL 注入：

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## 标记为已读的幂等性

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

路由处理器对 `ok` 和 `already_read` 都返回 200——使该端点可以多次调用而不产生副作用。

## 安全模式

| 模式 | 实现 |
|---------|----------------|
| **类型允许列表** | `in_array($type, ALLOWED_TYPES, true)` — 严格匹配 |
| **IDOR → 404** | 返回 404（非 403）以隐藏用户/通知是否存在 |
| **所有权验证** | 获取通知，在标记为已读前检查 `user_id` |
| **管理员故障关闭** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | 路径参数 ID 校验——ReDoS 安全 |
| **分页钳制** | `max(1, min(100, $limit))` + `PDO::PARAM_INT` 绑定 |
| **`is_int()` + `> 0`** | 严格 user_id 检查——拒绝浮点数、字符串、负数 |

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受自由格式 `type` 字符串 | 未经校验的类型污染收件箱；无法按有意义的类别过滤 |
| 未授权通知访问返回 403 | 暴露通知或用户是否存在——IDOR 信息泄露 |
| 在所有权检查之前从标记为已读返回 404 | 攻击者知道通知存在并属于某人 |
| 允许空 `adminKey` 表示"允许管理员" | 故障开放；如果未配置密钥，任何请求都成为管理员 |
| 直接信任查询字符串中的原始 `limit` | `limit=999999` 的请求导致全表扫描 |
| 在 LIMIT/OFFSET 中使用字符串插值 | 带未校验输入的 `"LIMIT {$limit}"` 允许 SQL 注入 |
