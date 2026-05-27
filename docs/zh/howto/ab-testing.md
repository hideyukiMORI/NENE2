# 操作指南：A/B 测试框架

> **FT 参考**：FT293（`NENE2-FT/ablog`）——A/B 实验框架：通过 crc32 种子实现加权确定性变体分配，draft→active→stopped 状态机，UNIQUE(experiment_id, user_id) 幂等分配，SQL 中 CVR 聚合，16 个测试 / 26 个断言全部通过。

通过将用户分配到不同变体并收集转化事件来运行受控实验。

## 数据库结构

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## 路由

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/experiments` | 创建实验（初始状态为 `draft`） |
| `GET` | `/experiments` | 列出所有实验 |
| `GET` | `/experiments/{id}` | 获取实验及其变体 |
| `PUT` | `/experiments/{id}/status` | 变更状态 |
| `POST` | `/experiments/{id}/variants` | 添加变体 |
| `POST` | `/experiments/{id}/assign` | 将用户分配到变体（幂等） |
| `POST` | `/experiments/{id}/events` | 记录转化事件 |
| `GET` | `/experiments/{id}/results` | 按变体聚合的 CVR 结果 |

## 状态生命周期

```
draft → active → stopped
```

对无效的状态转换返回 422：

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## 确定性变体分配

用户必须始终落入相同的变体——使用 `crc32` 实现可复现的无状态分桶：

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

数据库在首次调用时存储分配结果；后续调用返回已存储的变体——确定性与数据库真值相结合。

## 幂等分配

```php
// 返回已有分配，不重新计算
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200，不是 201
}
// 首次调用：计算并存储
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## 结果聚合（CVR）

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

然后在 PHP 中计算 CVR：

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## 防护措施

- 只有 `active` 状态的实验才接受分配（否则返回 409）。
- 事件要求用户已被分配（否则返回 404）。
- `UNIQUE(experiment_id, user_id)` 在数据库层面防止重复分配。
- 权重必须为正整数；零权重的变体会被拒绝（422）。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 随机（非确定性）分配 | 同一用户每次调用可能获得不同变体，导致体验不一致 |
| 无 `UNIQUE(experiment_id, user_id)` | 并发分配会产生重复行，用户可能同时属于多个变体 |
| 允许在 `draft` 或 `stopped` 状态下分配 | draft 实验尚无有效变体；stopped 实验不应收集新数据 |
| 允许状态逆向转换 | `stopped → active` 重新开启已关闭的实验，污染历史数据 |
| 不验证权重（允许 0） | 总权重为零会导致分桶计算时除以零 |
| 在应用层获取所有行后循环计算 CVR | 性能差；应使用 `GROUP BY` SQL 聚合代替 |
| 不验证事件与分配的关联性 | 没有有效分配的事件会扭曲每个变体的转化率 |
