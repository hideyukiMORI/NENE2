# 内容举报与审核

内容（文章）举报和审核系统的实现指南。解说 RBAC（基于角色的访问控制）、IDOR 防护、幂等举报和单向状态转换。

## 概述

- 用户举报文章（幂等：对同一篇文章重复举报返回 200）
- 只有审核员可以查看举报列表、解决和驳回举报
- 举报者只能查看自己的举报（IDOR 防护）
- 状态仅支持单向转换：`pending → resolved / dismissed`

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/reports` | 举报文章（幂等） |
| `GET` | `/reports` | 举报列表（仅审核员） |
| `GET` | `/reports/{id}` | 举报详情（自己的举报或审核员） |
| `PUT` | `/reports/{id}/resolve` | 解决举报（仅审核员） |
| `PUT` | `/reports/{id}/dismiss` | 驳回举报（仅审核员） |

## 数据库设计

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

`UNIQUE (reporter_id, article_id)` 是幂等添加的基础。`CHECK` 约束在数据库层面保证有效的状态和举报理由。

## 幂等举报

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
$report = $this->repository->findReportById($id);

return $this->responseFactory->create($this->formatReport($report ?? []), 201);
```

返回 `201` = 新举报，`200` = 已有举报（由调用方通过状态码判别）。

## RBAC——角色检查

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

审核员专用端点在处理器开头验证角色。

## IDOR 防护

```php
$isModerator = $actor !== null && $actor['role'] === 'moderator';
$isReporter  = (int) $report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

`GET /reports/{id}` 只有"自己的举报"或"审核员"才能查看。`reporter_id` 不从请求体获取，始终从 `X-User-Id` 头设置。

## 状态转换（单向）

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

一旦转换为 `resolved` 或 `dismissed` 的举报无法重新操作。数据库的 `CHECK` 约束为应用层的校验漏洞提供备份保障。

## 路径参数获取

NENE2 Router 将路径参数存储在 `nene2.route.parameters` 属性中。

```php
// 正确的获取方式
$id = (int) Router::param($request, 'id');

// 错误（直接 getAttribute('id') 无法获取）
$id = (int) $request->getAttribute('id');
```

## reporter_id 的安全性

```php
// createReport：actorId 已从 X-User-Id 头确认
$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
```

`reporter_id` 忽略请求体中的 `reporter_id` 字段，使用已认证的 `X-User-Id`。这防止冒充其他用户。

## POST /reports 响应示例

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "This article contains repeated spam links",
  "status": "pending",
  "resolved_by": null,
  "resolved_at": null,
  "resolution_note": null,
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## PUT /reports/{id}/resolve 响应示例

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "...",
  "status": "resolved",
  "resolved_by": 3,
  "resolved_at": "2026-05-21T13:00:00+00:00",
  "resolution_note": "Article removed for TOS violation",
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## GET /reports 响应示例（审核员）

```json
{
  "reports": [
    {
      "id": 2,
      "reporter_id": 2,
      "article_id": 5,
      "reason": "harassment",
      "status": "pending",
      ...
    }
  ],
  "count": 1
}
```
