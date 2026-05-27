# 等待列表管理

基于位置的等待列表（顺序等待）实现指南。
涵盖动态位置计算、状态机、IDOR 防止和管理员专用端点。

## 概述

- 用户加入等待列表（可附带可选备注）
- **动态位置计算**：不在 DB 中存储位置，每次通过 `COUNT(*)` 动态计算
- 状态机：`waiting` → `approved` / `declined`（单向，不可撤销）
- 只有等待中的用户才能离开（`approved`/`declined` 后不可）
- 管理员可以列出全部条目、批准、拒绝（使用 `X-Admin-Key` 头部）
- 用户端响应不包含 `user_id`（防止 IDOR）

## 端点

| 方法 | 路径 | 认证 | 说明 |
|---|---|---|---|
| `POST` | `/waitlist` | `X-User-Id` | 加入等待列表 |
| `GET` | `/waitlist/me` | `X-User-Id` | 获取自己的条目和位置 |
| `DELETE` | `/waitlist/me` | `X-User-Id` | 离开等待列表 |
| `GET` | `/waitlist` | `X-Admin-Key` | 列出全部条目（管理员用） |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | 批准条目 |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | 拒绝条目 |

## 数据库设计

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'waiting',
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id` 上的 `UNIQUE` 约束保证每用户一条条目。
不设置位置列（动态计算）。

## 动态位置计算

按 `id` 的相对顺序计算等待中条目的位置：

```sql
SELECT COUNT(*) FROM waitlist_entries
WHERE status = 'waiting' AND id <= :id
```

优点：
- 每次离开、批准、拒绝时无需 UPDATE 全部条目
- 不会产生写入竞争
- `status` 非 `waiting` 的条目返回 `null`

```php
public function positionOf(WaitlistEntry $entry): ?int
{
    if ($entry->status !== WaitlistStatus::Waiting) {
        return null;
    }

    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM waitlist_entries
         WHERE status = 'waiting' AND id <= :id",
    );
    $stmt->execute(['id' => $entry->id]);

    return (int) $stmt->fetchColumn();
}
```

## 状态机

```
waiting ──→ approved
        └─→ declined
```

- `waiting` 只能转换为 `approved` 或 `declined`
- 一旦进入终止状态就不可更改（通过 `isTerminal()` 判断）
- 只有等待中的用户才能通过 `DELETE /waitlist/me` 离开

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## IDOR 防止

用户端端点（`/waitlist/me`）通过 `X-User-Id` 头部只能获取**自己的条目**。
路径中没有传入其他用户 `user_id` 的空间，响应中也不包含 `user_id`。

```php
/** 用户端响应（不含 user_id） */
public function toPublicArray(): array
{
    return [
        'id'         => $this->id,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
    ];
}

/** 管理员端响应（含 user_id） */
public function toAdminArray(): array
{
    return [
        'id'         => $this->id,
        'user_id'    => $this->userId,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
    ];
}
```

## 管理员认证

使用 `hash_equals()` 对 `X-Admin-Key` 头部进行恒定时间比较。
空的 adminKey 始终返回 `false`（fail-closed）：

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // fail-closed
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

## 路由顺序

`GET /waitlist/me` 必须在 `GET /waitlist` 之前注册。
否则 `me` 可能被 `{id}` 捕获：

```php
$this->router->post('/waitlist',            $this->handleJoin(...));
$this->router->get('/waitlist/me',          $this->handleMe(...));      // 在 /waitlist 之前
$this->router->delete('/waitlist/me',       $this->handleLeave(...));
$this->router->get('/waitlist',             $this->handleAdminList(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->post('/waitlist/{id}/decline', $this->handleDecline(...));
```

## X-User-Id 验证

防止整数溢出、零、负数、非数字：

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');

    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

## 安全要点

| 威胁 | 对策 |
|---|---|
| IDOR | `/waitlist/me` 只允许访问自己的条目，响应不含 `user_id` |
| 管理员密钥嗅探 | `hash_equals()` 恒定时间比较 |
| 整数溢出 | `strlen > 18` 守卫 |
| 重复加入 | `UNIQUE(user_id)` 约束 → 409 |
| 非法状态转换 | `isTerminal()` 禁止终止状态后的变更 |
| SQL 注入 | PDO 参数化语句 |

## 响应示例

```json
// POST /waitlist (201)
{
    "entry": { "id": 1, "status": "waiting", "note": "希望 VIP", "created_at": "..." },
    "position": 1
}

// GET /waitlist/me — 已批准时 (200)
{
    "entry": { "id": 1, "status": "approved", "note": "希望 VIP", "created_at": "..." },
    "position": null
}

// GET /waitlist (管理员, 200)
{
    "data": [
        { "id": 1, "user_id": 101, "status": "approved", "note": "希望 VIP", ... },
        { "id": 2, "user_id": 102, "status": "waiting",  "note": null,      ... }
    ],
    "total": 2
}
```

## 相关指南

- [系统公告管理](system-announcement-management.md) — 管理员密钥认证模式（同样使用 `hash_equals()`）
- [隐私同意管理](privacy-consent-management.md) — UPSERT 与幂等操作
- [软删除](soft-delete.md) — 删除标志模式（离开是物理删除）
- [防止预订重复](prevent-double-booking.md) — 通过 UNIQUE 约束防止竞争
