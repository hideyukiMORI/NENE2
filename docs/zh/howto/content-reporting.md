# 操作指南：内容举报系统

> **FT 参考**：FT289（`NENE2-FT/reportlog`）——内容举报：允许列表的举报理由（ReportReason 枚举），`UNIQUE(reporter_id, article_id)` 配合重复时的幂等 200 响应，pending→resolved/dismissed 状态机，仅审核员可列出/解决/驳回，数据库层面 CHECK 约束，32 个测试 / 58 个断言全部通过。

本指南展示如何构建内容举报系统，让用户可以标记内容，审核员可以审查和解决举报。

## 数据库结构

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

数据库层面的 `CHECK` 约束即使应用校验被绕过也能强制枚举值有效。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/reports` | `X-User-Id` | 提交举报 |
| `GET` | `/reports` | 审核员 | 列出所有举报 |
| `GET` | `/reports/{id}` | 举报者或审核员 | 获取举报 |
| `PUT` | `/reports/{id}/resolve` | 审核员 | 解决举报 |
| `PUT` | `/reports/{id}/dismiss` | 审核员 | 驳回举报 |

## ReportReason 枚举

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` 拒绝未知值。处理器在错误响应中返回有效的举报理由：

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## 幂等举报提交

如果用户已经举报了同一篇文章，返回现有举报并附上 200（而非 201）：

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// 第一次：201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` 在数据库层面作为备份保障。应用先检查以返回友好的响应，但 UNIQUE 约束是安全网。

## 状态生命周期

```
pending ──→ resolved（审核员操作）
       └──→ dismissed（审核员操作）
```

一旦解决或驳回，举报无法再次转换。尝试更改非待处理举报的状态返回 422：

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## 审核员角色检查

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

角色存储在 `users` 表中，并在每个特权操作上检查。数据库层面的 `CHECK (role IN ('user', 'moderator'))` 防止插入无效角色。

## 访问控制：举报者与审核员

GET `/reports/{id}` 原始举报者和审核员都可以访问：

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

举报者可以查看自己的举报以跟踪状态。审核员可以看到所有举报。

## 带审计跟踪的解决

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by`（审核员 ID）、`resolved_at`（时间戳）和 `resolution_note`（可选）为每个审核操作创建审计跟踪。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受自由格式的举报理由字符串 | 拼写错误、注入、无限类别；使用枚举允许列表 |
| 无 `UNIQUE(reporter_id, article_id)` | 同一用户对同一篇文章提交多次举报；队列膨胀 |
| 重复举报时返回 409 | 重试安全的幂等性：重复 → 200 附现有举报，而非错误 |
| 允许从 resolved/dismissed 转换 | 已解决的举报被重新打开；审计跟踪变得不可靠 |
| 列表/解决操作无审核员角色检查 | 任何用户都能读取所有举报；隐私违规 + 审计绕过 |
| 向其他用户返回举报者自己的举报 | IDOR——始终检查举报者 === 操作者 或 操作者是审核员 |
| 无 `resolution_note` 字段 | 审核员无法说明举报被驳回还是解决的原因 |
| 无 `resolved_by` 字段 | 无法审计哪个审核员采取了行动 |
| 仅数据库 CHECK，无应用校验 | 无效理由触发数据库异常；用户收到 500 而非 422 |
