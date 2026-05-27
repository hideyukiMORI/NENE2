# 操作指南：功能标志 API

> **FT 参考**：FT313（`NENE2-FT/flaglog`）——功能标志管理：按环境分隔的标志，`rollout_percent` 渐进发布，按用户覆盖，带覆盖解析的 evaluate 端点，snake_case 键名校验，18 个测试 / 29 个断言全部通过。

本指南展示如何构建支持按环境配置、按百分比渐进发布和按用户覆盖的功能标志系统。

## 数据库结构

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` 必须匹配 `^[a-z][a-z0-9_]*$`（snake_case）。`rollout_percent` 范围为 0–100。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `PUT` | `/flags/{key}` | 创建或更新标志 |
| `GET` | `/flags` | 列出所有标志（可选 `?environment=`） |
| `GET` | `/flags/{key}/evaluate` | 为用户评估标志（`?user_id=`） |
| `PUT` | `/flags/{key}/overrides/{userId}` | 设置按用户覆盖 |
| `DELETE` | `/flags/{key}/overrides/{userId}` | 删除按用户覆盖 |

## 标志 Upsert——PUT /flags/{key}

```php
// 请求体
{
    "enabled": true,
    "rollout_percent": 50,   // 可选，默认 100
    "environment": "staging" // 可选，默认 "production"
}

// 响应 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

同一端点用于创建或更新（按 `key + environment` 进行 UPSERT）。使用不同值发送两次 `PUT` 会更新标志。

## 键名校验

```php
// 有效的键（snake_case：a-z、0-9、下划线，以字母开头）
dark_mode, beta_ui, new_feature_v2

// 无效——返回 422
Dark-Mode   // 大写 + 连字符
123flag     // 以数字开头
my flag     // 包含空格
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## 发布百分比校验

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## 按环境分隔的标志

```php
// 同一键，不同环境
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// 按环境列出
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## 评估——发布百分比 + 覆盖

```
GET /flags/{key}/evaluate?user_id={userId}
```

解析优先级：
1. **覆盖优先**：若 `flag_overrides` 中存在 `(key, environment, user_id)` 行 → 使用覆盖值
2. **标志禁用**：若 `enabled = false` → 无论发布百分比如何均返回 `false`
3. **发布检查**：对 `user_id` 进行确定性哈希 → 与 `rollout_percent` 比较

```php
// 1. 检查覆盖
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. 标志禁用
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. 发布百分比
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

响应：
```json
{"enabled": true, "override": null}   // 发布决策
{"enabled": true, "override": true}   // 覆盖启用
{"enabled": false, "override": false} // 覆盖禁用
```

## 按用户覆盖

```php
// 为特定用户启用（即使标志关闭 / 发布比例为 0%）
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// 为特定用户禁用（即使标志开启 / 发布比例为 100%）
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// 删除覆盖——恢复到全局标志 + 发布逻辑
DELETE /flags/my_flag/overrides/alice
```

覆盖需要 `enabled` 字段（布尔型）。缺少该字段 → 422。
对不存在的标志设置覆盖 → 404。
删除不存在的覆盖 → 404。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 允许任意键格式（如连字符、大写） | 团队间键名不一致；代码中难以 grep/引用 |
| 发布百分比 > 100 | 逻辑错误；110% 发布意味着始终启用，即便本意是渐进发布 |
| 无环境分隔 | staging 标志影响 production；金丝雀部署失效 |
| evaluate 时不检查 `user_id` | `crc32(null)` 或空字符串给出确定性但错误的分桶结果 |
| 对不存在的标志 evaluate 返回 200 | 调用方误以为标志存在；静默地将其视为禁用而非触发告警 |
| 内存/缓存中的全局标志状态无 TTL | 修改发布百分比后标志过期；变更未传播 |
