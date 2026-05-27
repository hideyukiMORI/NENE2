# 如何添加入站 Webhook 接收器

接收来自多个外部服务的 Webhook，按来源验证 HMAC 签名，并以幂等方式存储事件。

## 数据库结构

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/sources` | 注册 Webhook 来源 |
| `POST` | `/sources/{id}/receive` | 接收 Webhook |
| `GET` | `/sources/{id}/events` | 列出已接收的事件 |
| `GET` | `/events/{id}` | 获取特定事件 |

## HMAC-SHA256 签名验证

每个来源有各自的 HMAC 密钥。绝不在响应中暴露密钥。

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // 时序安全
}
```

调用顺序：**先验证签名**，再检查幂等性，最后存储：

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... 幂等性检查 ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## 幂等性（按来源的 event_id）

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

`UNIQUE(source_id, event_id)` 约束是 DB 层面的最终保障。上面的 PHP 检查避免了首次重复时走异常路径。

## 绝不暴露密钥

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // 返回前移除
return $this->json->create($source, 201);
```

## 非活跃来源检查

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## MySQL 注意事项

MySQL 中的 `UNIQUE KEY uq_source_event (source_id, event_id)` 约束效果相同。对索引文本列使用 `VARCHAR(191)` 以保持在 InnoDB 的键长度限制内。

### 运行 MySQL 集成测试

启动共享 FT MySQL 容器（端口 3308，持久化卷）：

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

然后用环境变量运行集成测试：

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

不设置 `MYSQL_HOST` 时，MySQL 测试会自动跳过（`markTestSkipped`）。

## 安全说明

- `hash_equals()` 防止签名比较的时序攻击。
- 原始 JSON 请求体原样存储；在签名验证之前不要解析。
- 来自两个不同来源的相同 `event_id` 会创建各自独立的记录——UNIQUE 约束是 `(source_id, event_id)`，而非仅 `event_id`。
