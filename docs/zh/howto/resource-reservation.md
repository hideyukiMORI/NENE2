# 操作指南：资源预约/时间段预订 API

本指南展示如何使用 NENE2 构建带重叠防止的时间段预订系统。模式由 **reservationlog** 字段试验（FT216）验证。

## 功能

- 创建命名资源（会议室、设备等）——仅管理员
- 预订时间段并自动检测重叠
- 按资源（管理员）或按用户（本人）列出预订
- 取消预订并验证所有权
- 公开响应排除 `user_id`（IDOR 防护）
- 管理员视图包含 `user_id` 用于审计

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- 快速重叠查询的索引
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## 端点

| 方法 | 路径 | 认证 | 说明 |
|--------|------|------|-------------|
| `POST` | `/resources` | 管理员 | 创建资源 |
| `GET` | `/resources/{id}/bookings` | 管理员 | 列出资源的所有预订 |
| `POST` | `/resources/{id}/book` | 用户 | 预订时间段 |
| `GET` | `/bookings` | 用户 | 列出自己的预订 |
| `DELETE` | `/bookings/{id}` | 用户 | 取消自己的预订 |

## 重叠检测

两个时间范围 `[A.start, A.end)` 和 `[B.start, B.end)` 重叠当且仅当：

```
A.start < B.end AND A.end > B.start
```

这正确处理了所有重叠情况（包含、重叠、相同），同时允许相邻时间段（A.end = B.start 是 OK 的——半开区间语义）。

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## 值对象

使用 readonly 值对象实现领域清晰性：

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** 公开视图：排除 user_id（IDOR 防护） */
    public function toPublicArray(): array { ... }

    /** 管理员视图：包含 user_id 用于审计 */
    public function toAdminArray(): array { ... }
}
```

## IDOR 防护

预订以不同字段暴露公开和管理员视图：

```php
// 用户：GET /bookings——公开视图（无 user_id）
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// 管理员：GET /resources/{id}/bookings——管理员视图（包含 user_id）
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

取消时，当用户尝试取消他人预订时返回 403（而非 404），因为预订 ID 已经可见（不隐藏存在性）：

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## 安全模式

- **管理员故障关闭**：`hash_equals()` 前执行 `if ($this->adminKey === '') return false;`
- **`ctype_digit()`**：路径 ID 的 ReDoS 安全整数校验
- **ISO 8601 校验**：正则模式 + 字典序比较（UTC 下有效）
- **备注长度限制**：`mb_strlen($note) > 500` 返回 422
- **级联删除**：`ON DELETE CASCADE` 确保预订随资源一起删除

## VULN + ATK 评估（FT216）

此 FT 通过完整的 VULN-A 到 VULN-L 和 ATK-01 到 ATK-12 评估：

- **VULN-B**：无批量赋值——资源/预订字段被显式绑定
- **VULN-C**：取消对错误所有者返回 403；资源/预订查找使用类型化 ID
- **VULN-D**：管理员故障关闭——空管理员密钥始终返回 false
- **VULN-F**：ISO 8601 正则防止日期时间注入
- **VULN-G**：`ctype_digit()` 守护所有整数路径参数
- **ATK-01**：SQL 注入通过参数化查询被阻止
- **ATK-02/03**：ID 中的整数溢出被 `strlen > 18` 守护阻止
- **ATK-06**：认证绕过被故障关闭的管理员检查阻止
- **ATK-09**：重叠逻辑正确防止重复预订
