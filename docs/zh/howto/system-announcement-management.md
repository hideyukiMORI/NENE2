# 操作指南：系统公告管理

> **FT190 announcelog** — 基于时间的系统公告，含管理员密钥认证、每用户忽略功能和优先级排序。38 tests / 93 assertions 全部 PASS。

---

## 本指南涵盖

用于广播维护通知、功能更新和警报的系统公告 API：

1. **创建/更新/删除** — 通过恒定时间密钥比较实现的管理员专用操作
2. **列出活跃公告** — 按 `starts_at` / `ends_at` 进行 UTC 时间过滤
3. **忽略** — 以幂等 UPSERT 持久化每用户的选出操作

---

## 数据库结构

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- 数值越大越靠前显示
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` 支持幂等忽略操作。`starts_at` / `ends_at` 是 ISO 8601 字符串——UTC 日期时间的字典序比较是正确的。

---

## API

| 方法 | 路径 | 认证 | 说明 |
|---|---|---|---|
| `POST` | `/announcements` | `X-Admin-Key` | 创建公告（201） |
| `PUT` | `/announcements/{id}` | `X-Admin-Key` | 更新公告（200） |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | 删除公告（200） |
| `GET` | `/announcements` | 可选 `X-User-Id` | 列出当前活跃公告 |
| `POST` | `/announcements/{id}/dismiss` | `X-User-Id` | 为该用户忽略（200） |

---

## 核心模式：恒定时间管理员密钥验证

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // 空 adminKey 配置意味着无管理员访问——失闭合
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals：恒定时间——防止密钥比较的时序攻击
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**为什么不用 `===`：** 字符串比较在第一个不匹配处短路。攻击者可以通过测量时序差异找到部分前缀匹配，然后逐字符暴力破解。`hash_equals()` 无论不匹配在哪里都花费恒定时间。

**失闭合：** 空 `adminKey` 配置始终返回 `false`——不会出现意外的"开放管理员"模式。

---

## 核心模式：基于 UTC 时间的过滤

```php
// 列出当前活跃的公告
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

UTC 格式的 ISO 8601 字符串字典序排列正确——`'2025-06-01T...' > '2025-05-01T...'`。始终在数据库中使用 UTC。

`ends_at > :now`（严格大于）意味着公告在 `ends_at` 时刻精确过期，而不是此后一秒。

---

## 核心模式：每用户忽略（幂等）

```php
// UNIQUE(user_id, announcement_id) 支持安全的重复忽略调用
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

用户两次调用 `POST /announcements/5/dismiss` 是安全的——第二次调用静默成功。客户端无需提前检查。

---

## 核心模式：列表中的可选用户上下文

```php
// 不带 X-User-Id：显示所有活跃公告
// 带 X-User-Id：排除该用户已忽略的公告

// 不带用户：
WHERE a.starts_at <= :now AND a.ends_at > :now

// 带用户（LEFT JOIN + IS NULL 过滤）：
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

这个单一的 `GET /announcements` 端点同时处理未认证（监控、管理员视图）和已认证（UI 显示相关横幅）两种使用场景。

---

## 核心模式：`ends_at` 必须晚于 `starts_at`

```php
// 服务器端验证——不仅仅是信任客户端
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at must be after starts_at.';
}
```

`ends_at <= starts_at` 的公告在创建后立即不可见——应当验证并拒绝，而不是静默接受损坏的数据。

---

## 响应设计

| 场景 | 状态码 | 响应体 |
|---|---|---|
| 创建成功 | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| 更新成功 | 200 | `{announcement: {...}}` |
| 删除成功 | 200 | `{deleted: true}` |
| 列出活跃公告 | 200 | `{data: [...], total: N}` |
| 忽略 | 200 | `{dismissed: true}` |
| 管理员密钥缺失/错误 | 401 | `{error: "Admin key required."}` |
| 未找到 | 404 | `{error: "Announcement not found."}` |
| 验证失败 | 422 | `{error: "..."}` |

`created_at` / `updated_at` **不**出现在公开响应中——它们是内部元数据。

---

## 测试结果（FT190）

```
38 tests / 93 assertions — 全部 PASS
PHPStan level 8 — 无错误
PHP CS Fixer — 代码整洁
```

源码：[`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
