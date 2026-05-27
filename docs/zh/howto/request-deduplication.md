# 如何添加请求去重

使用 `Idempotency-Key` 头防止网络重试或双击导致的重复处理。服务器按密钥缓存响应，并在后续相同请求时重放。

## 数据库结构

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

## 路由

| 方法 | 路径 | 说明 |
|--------|------|-------------|
| `POST` | `/payments` | 处理支付（需要 idempotency-key） |
| `POST` | `/orders` | 创建订单（需要 idempotency-key） |

## 处理器模式

每个需要幂等的变更端点都遵循相同的三步模式：

```php
// 1. 要求 Idempotency-Key 头
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. 如果密钥已使用，返回缓存响应
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. 处理并缓存
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

`replayed: true` 字段向客户端表明响应来自缓存。

## 严格金额校验

在边界拒绝非整数输入——PHP 的 `(int)` 强制转换会静默截断字符串如 `"100; DROP TABLE …"` 为 `100`。使用显式类型检查：

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL 与过期

密钥在 24 小时（86400 秒）后过期。过期条目被视为新的——同一密钥可在过期后重用：

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## 安全属性

- **通过密钥头的 SQL 注入**：参数化查询将恶意密钥存储为字面量。
- **重放洪泛**：10 个相同请求在业务表中恰好创建 1 条记录。
- **纯空白密钥**：检查前先 `trim()` 防止 `"   "` 作为有效密钥。
- **数字字段中的类型注入**：`ctype_digit()` 检查拒绝部分整数字符串。
- **无内部泄露**：400/422 响应只包含 `error` 或 `errors` 字段——不含路径、堆栈跟踪或引擎详情。
