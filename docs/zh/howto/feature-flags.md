# 操作指南：功能标志 API

> **FT 参考**：FT270（`NENE2-FT/featureflaglog`）——功能标志 API：优先级链评估（用户目标 → 租户目标 → globally_enabled → rollout_pct 哈希），基于 crc32 的确定性分桶，用户/租户终止开关，标志名称 UNIQUE 约束，21 个测试 / 31 个断言全部通过。

功能标志允许在不部署代码的情况下在运行时切换功能。核心决策在于：状态存储位置（DB vs 配置文件）、多条规则同时适用时的评估优先级，以及如何在不进行用户级别跟踪的情况下处理发布百分比。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/flags` | 创建新功能标志 |
| `GET` | `/flags/{name}` | 获取标志详情及目标列表 |
| `POST` | `/flags/{name}/toggle` | 设置 globally_enabled 开/关 |
| `PUT` | `/flags/{name}/rollout` | 设置发布百分比（0–100） |
| `PUT` | `/flags/{name}/targets` | Upsert 用户或租户目标覆盖 |
| `DELETE` | `/flags/{name}/targets/{type}/{id}` | 删除特定目标覆盖 |
| `POST` | `/flags/{name}/evaluate` | 为用户/租户评估标志 |

---

## 核心组件

- **功能标志注册表**：每个标志一行，包含名称、全局开关和发布百分比。
- **标志目标**：优先于全局状态的按用户或按租户覆盖。
- **评估器**：应用优先级链并为给定用户返回布尔值。

## 数据库结构

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## 评估优先级

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. 用户级别目标优先
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. 租户级别目标
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. 全局开关
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. 发布百分比：通过 crc32 哈希确定性分桶
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. 默认关闭
        return false;
    }
}
```

优先级顺序（最高优先级优先）：
1. 用户级别目标（`target_type = 'user'`）
2. 租户级别目标（`target_type = 'tenant'`）
3. `globally_enabled = 1`
4. `rollout_pct > 0` 并基于哈希分桶
5. `false`

## 发布百分比——确定性分桶

`crc32($userId . '.' . $flagName) % 100` 为每个（用户，标志）对生成稳定的分桶值。同一用户始终落入同一分桶，因此跨请求的体验一致。附加标志名称是为了防止所有标志在 `pct = 10` 时向同一批用户发布。

重要提示：`crc32()` 在 64 位系统上可能返回负值——请使用 `abs()`。

## 目标作为覆盖

`enabled = false` 的目标是终止开关：即使 `globally_enabled = 1`，它也会为该用户或租户禁用标志。这是在已全局启用的发布中排除特定用户的标准方法。

```php
// 用户级别终止开关（覆盖全局启用）
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// 租户早期访问（覆盖全局禁用）
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## 目标 Upsert 模式

目标使用 `INSERT OR REPLACE` / upsert 语义——用不同 `enabled` 值两次调用同一端点会更新现有行，而不是创建重复行：

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

`(flag_id, target_type, target_id)` 上的 UNIQUE 约束确保每个（标志，目标）对最多只有一条覆盖记录。

## 重复标志名的冲突响应

`feature_flags.name` 有 UNIQUE 约束。创建重复时，DB 抛出 `RuntimeException`。捕获它并返回 409 Conflict 而非 500：

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // 调用方将 null 映射为 409
}
```

## 设计决策

**为何使用 DB 而非配置文件？**
配置文件需要部署才能更改标志。DB 支持的标志可以在不触碰代码或重启进程的情况下实时切换。

**为何使用确定性哈希而非随机数进行发布？**
随机选择意味着同一用户在不同请求间会在启用/禁用之间切换。稳定的哈希为每个用户提供标志生命周期内一致的体验。

**为何允许 `enabled = false` 的目标？**
没有终止开关的标志系统是不完整的。`enabled = false` 是在全局启用的发布中排除用户最安全的方式——无需代码更改，无需部署。

**为何将 `globally_enabled` 和 `rollout_pct` 分开？**
`globally_enabled = 1` 是明确的全有全无开关。`rollout_pct` 用于渐进曝光。分开维护可避免一个字段承载两种不同含义。

---

## 示例响应

**POST /flags**（201 Created）：
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}**（200 OK）：
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate**（200 OK）：
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 每次请求使用随机数进行发布 | 同一用户在不同请求间启用/禁用切换——体验不一致 |
| 忘记对 `crc32()` 使用 `abs()` | crc32 在 64 位 PHP 上可能返回负值——取模结果分桶错误 |
| 允许任意 `target_type` 值 | 不受控制的枚举使评估逻辑无界；限制为 `'user'` 和 `'tenant'` |
| 无 `UNIQUE (flag_id, target_type, target_id)` | 重复目标使评估模糊——任意以第一行为准 |
| 使用标志名称作为 `target_id` | 标志名称可能变化；使用稳定 ID 进行用户/租户目标定位 |
| 标志名称重复时返回 500 | 名称唯一性违反是领域错误，而非服务器错误；应映射为 409 Conflict |
