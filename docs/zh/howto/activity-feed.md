# 操作指南：活动动态/时间线 API

> **FT 参考**：FT277（`NENE2-FT/feedlog`）——活动动态：类型白名单事件（9 种类型），每个事件的 JSON 载荷，带 IDOR → 404 保护的用户范围动态，分页限制（最大 100），管理员失败关闭，24 个测试 / 37 个断言全部通过。
>
> 同时在 FT219（`NENE2-FT/feedlog` 前身）中进行了验证——对相同模式进行了漏洞评估。

本指南展示如何使用 NENE2 构建带有类型化事件、用户范围限定和分页功能的活动动态系统。

## 功能特性

- 发布类型化活动事件（严格白名单类型）
- JSON 载荷存储（每种事件类型的任意元数据）
- 带 IDOR 保护的用户范围动态（未授权访问返回 404）
- 通过查询参数过滤事件类型
- 时间戳降序分页（最新优先）
- 管理员可代表用户发布事件

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/events` | 用户 | 发布活动事件 |
| `GET` | `/users/{userId}/feed` | 用户（本人或管理员） | 获取动态（可按类型过滤） |

## 事件类型白名单（VULN-B）

严格的事件类型白名单可以防止批量赋值和任意事件注入：

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## 载荷存储

载荷以 JSON 字符串存储，检索时解码：

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## IDOR 防护（VULN-C）

当未授权用户尝试查看其他用户的动态时，动态访问返回 404（而非 403）：

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## 带类型过滤的分页

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

`?type=` 参数中的未知类型会被静默忽略（null = 不应用过滤）。

## 漏洞评估结果（FT219）

- **VULN-B**：`in_array(..., strict: true)` 阻止任何不在列表中的事件类型
- **VULN-C**：IDOR 返回 404，向未授权调用者隐藏动态的存在
- **VULN-D**：管理员失败关闭——空管理员密钥始终返回 false
- **VULN-F**：`is_array($payload)` 确保载荷始终是 JSON 对象，而非标量
- **VULN-G**：`ctype_digit()` 保护 `userId` 路径参数
- **VULN-I**：`clampInt()` 限制 `limit`（1–100）和 `offset`（0–MAX_INT）的范围

## 安全模式

- **`ctype_digit()`**：对路径参数进行抗 ReDoS 的整数验证
- **`is_array()`**：载荷必须是 JSON 对象（PHP 中的 array）——不能是字符串、数字、null
- **参数化查询**：所有 SQL 使用 `:named` 参数——不进行字符串拼接
- **`in_array(..., true)`**：严格比较，防止类型强制转换绕过

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受自由格式的事件类型字符串 | 不受控的类型污染动态；难以构建特定类型的查询 |
| 不经 JSON 验证直接以 TEXT 存储载荷 | `is_array($payload)` 确保 JSON 对象；标量/数组会破坏下游消费者 |
| 信任查询字符串中的原始 `limit` | 无上限 → 大数据集上的全表扫描 |
| 使用不带 `true` 的 `in_array($type, TYPES)` | 松散比较；某些 PHP 版本中 `0 == 'post_created'` 为真 |
| 错误用户访问动态时返回 403 | 暴露用户存在；使用 404 隐藏用户枚举 |
| 只在 `user_id` 上建索引 | 复合索引中缺少 `id DESC` 会导致大型动态的 ORDER BY 变慢 |
