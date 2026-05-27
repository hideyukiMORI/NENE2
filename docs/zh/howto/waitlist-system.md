# 操作指南：等待列表系统

> **FT 参考**：FT287（`NENE2-FT/waitlistlog`）— 等待列表系统：UNIQUE(user_id) 单条目约束、waiting→approved/declined 状态机、isTerminal() 守卫、/waitlist/me 在 /{id} 之前注册以防止路由捕获、X-Admin-Key 认证、队列位置跟踪，39 tests / 98 assertions 全部 PASS。

本指南展示如何构建等待列表系统，用户加入队列，管理员批准或拒绝条目。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- 每用户一条条目
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- 可选用户备注（最多 500 字符）
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` 在 DB 层强制每用户一条条目——无需应用层检查竞争条件。

## 端点

| 方法 | 路径 | 认证 | 说明 |
|---|---|---|---|
| `POST` | `/waitlist` | `X-User-Id` | 加入等待列表 |
| `GET` | `/waitlist/me` | `X-User-Id` | 获取自己的状态 + 位置 |
| `DELETE` | `/waitlist/me` | `X-User-Id` | 离开等待列表 |
| `GET` | `/waitlist` | `X-Admin-Key` | 管理员：列出全部条目 |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | 管理员：批准条目 |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | 管理员：拒绝条目 |

## 路由注册顺序

`/waitlist/me` 必须在 `/waitlist/{id}` **之前**注册，以防止路径参数捕获字面字符串 `"me"`：

```php
// 正确：静态路径在动态路径之前
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// 错误：{id} 会捕获 "me"
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // 永远不会被匹配到
```

## 状态生命周期

```
waiting ──────→ approved（终止）
       └──────→ declined（终止）
```

一旦批准或拒绝，条目就不能转换到其他状态。`isTerminal()` 方法守卫这一点：

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

## 重复加入时返回 409

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

当 `user_id` 已存在时，repository 返回 `null`（从 `DatabaseConstraintException` 捕获）。响应为 409 Conflict。

## 位置跟踪

```php
$position = $this->repository->positionOf($entry);

// positionOf() 统计 status='waiting' 且 id <= $entry->id 的条目数
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

位置是 `waiting` 队列中基于 1 的排名。已批准/拒绝的条目不计入。这给用户提供了有意义的排队位置。

## 管理员状态转换（使用 match）

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` 是穷举的——`default` 分支捕获 repository 的任何意外返回值。

## 离开（仅限等待中）

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

一旦批准或拒绝，用户就不能离开——其决定已被记录。这防止了对系统的操控（批准后离开以避免跟踪）。

## 管理员认证

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // fail-closed：未配置密钥 → 无管理员访问
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` 防止时序攻击。空管理员密钥始终返回 false（fail-closed）。

## 备注验证

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

备注是可选的（缺失/空时为 null），最多 500 字符，超长时截断（而非拒绝）。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 无 `UNIQUE(user_id)` 约束 | 并发加入创建重复条目；竞争条件 |
| 在 `/me` 之前注册 `/{id}` | `/waitlist/me` 变得不可达——被 `{id}` 捕获 `"me"` |
| 允许从终止状态转换 | 已批准条目在授权后被拒绝；状态机被破坏 |
| 允许从终止状态离开 | 已批准用户离开；访问授权变成孤儿 |
| 基于 `id ASC` 统计全部条目计算位置 | 计入了已批准/拒绝的用户；位置数字具有误导性 |
| 将管理员密钥存入 DB | 密钥轮换需要 DB 更新；应使用环境变量 |
| 使用 `==` 而非 `hash_equals()` 比较管理员密钥 | 时序攻击逐字符揭示密钥 |
| 无管理员 fail-closed | 环境变量中空密钥允许未认证的管理员访问 |
| 备注超长时拒绝 | 用户体验：对备注等软性元数据进行截断比拒绝更友好 |
