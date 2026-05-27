# 操作指南：反馈收集 API

## 概述

用户对目标实体提交分数（1-5）和评论的反馈系统。管理员可以列出所有反馈；公开统计端点展示聚合平均值。

**参考实现**：`../NENE2-FT/feedbacklog/`

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## 路由

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/feedback` | 用户 | 提交反馈 |
| `GET` | `/feedback` | 管理员 | 列出所有反馈 |
| `GET` | `/feedback/stats` | 无 | 聚合统计 |

## 重复提交防止

`UNIQUE (user_id, target)` 在 DB 层面强制每用户每目标只能提交一条反馈。应用层先进行检查：

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## 分数校验

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## 统计聚合

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

当 count 为零时返回 `null` 平均值，以避免 JSON 中出现 `NaN`。

## HTTP 状态码

| 情况 | 状态码 |
|------|--------|
| 反馈提交成功 | 201 |
| 统计 / 列表 | 200 |
| 缺少 X-User-Id | 400 |
| 空目标 / 分数无效 | 422 |
| 缺少管理员密钥 | 403 |
| 重复反馈 | 409 |
