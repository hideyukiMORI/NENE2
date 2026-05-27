# 操作指南：审批工作流 API

> **FT 参考**：FT68（`NENE2-FT/approvallog`）——审批工作流 API

演示一个多步骤审批工作流，请求在定义的状态中流转（草稿→已提交→审核中→已批准/已拒绝）。无效的状态转换返回 409 Conflict。状态机直接编码在 `ApprovalStatus` 背后枚举中，通过 `allowedTransitions()` 方法实现。

---

## 工作流状态

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| 状态 | 描述 |
|-------|-------------|
| `draft` | 已创建但尚未提交 |
| `submitted` | 等待分配审核人 |
| `under_review` | 已分配审核人，正在审核中 |
| `approved` | 已给予最终批准 |
| `rejected` | 已拒绝，需填写必填理由 |

已拒绝的请求可以返工（退回到 `draft`）以进行修改和重新提交。已批准的请求没有进一步的状态转换。

---

## 编码在枚举中的状态转换规则

状态转换规则存在于枚举内部——而非仓库或控制器中：

```php
enum ApprovalStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted],
            self::Submitted   => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved    => [],
            self::Rejected    => [self::Draft],   // 返工路径
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` 是判断转换是否有效的唯一真实来源。添加新的允许转换只需更新此方法。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests` | 创建草稿请求 |
| `GET` | `/requests` | 列出所有请求（`?status=` 过滤） |
| `GET` | `/requests/{id}` | 获取单个请求 |
| `POST` | `/requests/{id}/submit` | 草稿→已提交 |
| `POST` | `/requests/{id}/review` | 已提交→审核中（分配审核人） |
| `POST` | `/requests/{id}/approve` | 审核中→已批准 |
| `POST` | `/requests/{id}/reject` | 审核中→已拒绝（必须填写理由） |
| `POST` | `/requests/{id}/rework` | 已拒绝→草稿（清除审核人/备注） |

---

## 在仓库中保护状态转换

仓库在执行 UPDATE 查询之前检查 `canTransitionTo()`：

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // 调用者将 null 映射为 409 Conflict
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

对"未找到"和"无效转换"都返回 `null` 是有意的简化。在生产环境中，通过返回类型化结果或抛出领域异常来区分 404（未找到）和 409（已找到但转换无效）。

控制器将 `null → 409 Conflict`：

```php
private function submit(ServerRequestInterface $request): ResponseInterface
{
    $id  = (int) ($params['id'] ?? 0);
    $req = $this->repo->submit($id, $now);

    if ($req === null) {
        return $this->problems->create(
            $request,
            'conflict',
            'Request not found or cannot be submitted from its current status.',
            409,
            '',
        );
    }

    return $this->json->create($req->toArray());
}
```

---

## 拒绝需要填写理由

`reject` 转换需要同时填写 `reviewer` 和 `note`：

```php
private function reject(ServerRequestInterface $request): ResponseInterface
{
    $reviewer = isset($body['reviewer']) && is_string($body['reviewer']) ? trim($body['reviewer']) : '';
    $note     = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

    if ($reviewer === '' || $note === '') {
        $errors = [];
        if ($reviewer === '') {
            $errors[] = ['field' => 'reviewer', 'code' => 'required', 'message' => 'reviewer is required.'];
        }
        if ($note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required', 'message' => 'note (rejection reason) is required.'];
        }

        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, compact('errors'));
    }
    // ...
}
```

没有理由的拒绝会被拒绝（422）。没有备注的批准是允许的——`note` 字段对于批准是可选的。

---

## 返工：清除审核状态

当已拒绝的请求被返工时，审核人和审核备注被清除，以便下一个审核人从头开始：

```php
// 仓库：返工（已拒绝 → 草稿）
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

`submitted_at` 时间戳被保留——它记录请求首次提交的时间，而非当前周期。

---

## 数据库结构

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- 审核开始前为 NULL
    review_note  TEXT,             -- 审核前为 NULL
    submitted_at TEXT,             -- 提交前为 NULL
    reviewed_at  TEXT,             -- 批准/拒绝前为 NULL
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

在返工时，可为空的列（`reviewer`、`review_note`、`submitted_at`、`reviewed_at`）被清除为 `NULL`，保持数据库结构整洁而无需添加 `rework_count` 列。

> **增强建议**：添加 `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))` 作为数据库层面的保护措施，与枚举值保持一致。

---

## 列表端点的状态过滤

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $params    = $request->getQueryParams();
    $statusRaw = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
    $status    = $statusRaw !== null ? ApprovalStatus::tryFrom($statusRaw) : null;

    if ($statusRaw !== null && $status === null) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'status', 'code' => 'invalid_value', 'message' => 'Invalid status value.']],
        ]);
    }

    $requests = $this->repo->listByStatus($status);
    // ...
}
```

`ApprovalStatus::tryFrom()` 对未知状态字符串返回 `null` → 422。当 `$statusRaw === null`（无过滤）时，返回所有请求。

---

## 添加新的状态转换

要添加可从任何非终止状态到达的 `cancelled` 状态：

1. 在 `ApprovalStatus` 中添加 `case Cancelled = 'cancelled';`。
2. 在 `Draft`、`Submitted` 和 `UnderReview` 的 `allowedTransitions()` 中添加 `self::Cancelled`。
3. 添加 `POST /requests/{id}/cancel` 路由和处理器。
4. 在仓库中编写数据库 UPDATE。
5. 更新数据库结构的 `CHECK` 约束（如果已添加）。

枚举是唯一的真实来源——无需更改其他文件即可添加转换保护。

---

## 相关操作指南

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) ——草稿→发布生命周期（更简单的状态机）
- [`media-watchlist.md`](media-watchlist.md) ——使用 `tryFrom()` 的背后枚举验证
- [`add-custom-route.md`](add-custom-route.md) ——POST 动作端点模式
- [`multi-step-workflow.md`](multi-step-workflow.md) ——通用多步骤工作流模式
