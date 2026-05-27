# 操作指南：使用 HMAC-SHA256 进行 Webhook 签名验证

> **FT 参考**：FT260（`NENE2-FT/hmaclog`）— Webhook 签名验证：HMAC-SHA256、时序安全比较、重放攻击防止
> **ATK**：FT260 — 攻击者思维攻击测试（ATK-01 至 ATK-12）

演示如何使用 Stripe 风格的 HMAC-SHA256 签名验证传入的 webhook 请求。
签名头部将时间戳与请求体绑定，防止伪造和重放攻击。
使用 `hash_equals()` 进行恒定时间比较以防止时序攻击。

---

## 路由

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/webhook` | 接收并验证已签名的 webhook |
| `GET` | `/webhook/events` | 列出接收到的 webhook 事件 |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    payload      TEXT NOT NULL,
    delivered_at TEXT NOT NULL
);
```

只有在签名验证通过后才存储事件。被拒绝的 webhook 不会被持久化。

---

## 签名格式（Stripe 风格）

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**签名 payload**：`"<timestamp>.<raw-body>"`

时间戳包含在 HMAC 计算中。这意味着：
- 有效签名只对其计算的 body 有效（篡改 body 会破坏签名）。
- 有效签名只在生成时的那一刻有效（重放旧的有效签名即使 HMAC 正确也会失败时间戳检查）。

---

## 验证器

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // 关键：hash_equals 是恒定时间的；=== 不是
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }
        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }
}
```

---

## 控制器：原始 body 提取

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // 必须是原始字节，而非已解析内容

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // 仅在验证成功后解析
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**关键顺序**：
1. 将原始 body 读取为字符串——HMAC 是对精确字节计算的。
2. 对原始 body 验证签名。
3. 只有验证成功后才解析 JSON。

如果先解析 JSON 再重新序列化，字节内容可能不同（键顺序、空白符），导致 HMAC 检查失败。

---

## ATK — 攻击者思维攻击测试（FT260）

### ATK-01 — 缺少签名头部

**攻击**：发送不带 `X-Webhook-Signature` 头部的 webhook。

```bash
POST /webhook
{"event_type": "user.created"}
```

**观察结果**：`verify()` 在任何计算之前检查 `$header === ''`。返回 401 Problem Details：
`"Missing X-Webhook-Signature header."`。不存储任何事件。

**判定**：**BLOCKED** — 缺少头部在签名计算之前就被捕获。

---

### ATK-02 — 篡改签名（单字符变更）

**攻击**：获取有效签名并更改一个十六进制字符。

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-but-one-char-wrong>
```

**观察结果**：`hash_equals($expectedSig, $receivedSig)` 返回 `false`。返回 401。
比较是恒定时间的——响应时间不随匹配的字符数变化。

**判定**：**BLOCKED** — `hash_equals()` 在拒绝篡改签名的同时防止时序预言攻击。

---

### ATK-03 — 使用错误 secret 签名

**攻击**：使用不同的 HMAC secret 签名请求。

```
X-Webhook-Signature: t=<now>,v1=<hmac-with-wrong-secret>
```

**观察结果**：`computeSignature()` 使用服务器的 secret。攻击者的 HMAC（用不同 secret 计算）产生不同的十六进制字符串。`hash_equals()` 失败。返回 401。

**判定**：**BLOCKED** — 没有 secret，无法伪造有效签名。

---

### ATK-04 — 重放攻击：有效的旧签名

**攻击**：捕获合法的 `X-Webhook-Signature` 头部并在 10 分钟后重放。

```
X-Webhook-Signature: t=<timestamp-from-10-minutes-ago>,v1=<valid-hmac>
```

**观察结果**：`checkTimestamp($timestamp)` 计算 `abs(time() - $timestamp)`。
10 分钟 = 600 秒 > 300 秒容忍度。抛出 `SignatureException`。返回 401。

**判定**：**BLOCKED** — 重放攻击被 300 秒时间戳容忍度击败。

---

### ATK-05 — 未来时间戳：绕过重放防御尝试

**攻击**：使用未来时间戳预签名请求以延长有效窗口。

```
X-Webhook-Signature: t=<now + 3600>,v1=<hmac-with-future-ts>
```

**观察结果**：`abs(time() - $timestamp)` = 3600 > 300。抛出 `SignatureException`。返回 401。
`abs()` 意味着未来时间戳也被拒绝——检查是对称的。

**判定**：**BLOCKED** — `abs()` 确保容忍窗口之外的过去和未来时间戳都被拒绝。

---

### ATK-06 — 使用有效签名篡改 body

**攻击**：拦截有效的 webhook。保留 `X-Webhook-Signature` 头部但修改 JSON body。

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-over-original-body>
Body: {"event_type": "user.deleted"}   ← 从"user.created"改为"user.deleted"
```

**观察结果**：HMAC 是对 `"<timestamp>.<original-body>"` 计算的。修改后的 body 产生不同的 HMAC。`hash_equals()` 失败。返回 401。

**判定**：**BLOCKED** — 签名将时间戳与 body 绑定。更改任一项都会使签名失效。

---

### ATK-07 — 格式错误的头部：缺少时间戳

**攻击**：提交不带 `t=` 组件的签名头部。

```
X-Webhook-Signature: v1=<some-hmac>
```

**观察结果**：`parseHeader()` 检查 `isset($parts['t'], $parts['v1'])`。缺少 `t` 抛出
`SignatureException('Malformed X-Webhook-Signature header.')`。返回 401。

**判定**：**BLOCKED** — 头部解析器强制要求必需字段。

---

### ATK-08 — 服务器 secret 为空

**攻击场景**：服务器配置了空 HMAC secret（`''`）。

**观察结果**：空 secret 在 PHP 的 `hash_hmac()` 中是有效的——它产生确定性的十六进制字符串。发现空 secret 的攻击者可以伪造有效签名：`hash_hmac('sha256', "{$timestamp}.{$body}", '')`。

**判定**：**EXPOSED（配置错误）** — 验证器不拒绝空 secret。
应用配置层必须在启动时验证 `WEBHOOK_SECRET` 非空。
Fail-closed 默认值：如果 secret 为空，拒绝所有 webhook。

```php
// 推荐的启动守卫
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — HMAC 绕过：提交 `v1=` 空值

**攻击**：将签名设置为空字符串：`X-Webhook-Signature: t=<now>,v1=`。

**观察结果**：`parseHeader()` 检查 `$parts['v1'] === ''`。空 `v1` 抛出
`SignatureException('Malformed X-Webhook-Signature header.')`。返回 401。

**判定**：**BLOCKED** — 空签名在 `hash_equals()` 调用之前就在解析器中被拒绝。

---

### ATK-10 — 时间戳注入：非数字时间戳

**攻击**：提交非纯整数的时间戳：`t=1234abc`。

```
X-Webhook-Signature: t=1234abc,v1=<some-hmac>
```

**观察结果**：`parseHeader()` 检查 `ctype_digit($parts['t'])`。非数字字符导致
`SignatureException('Malformed X-Webhook-Signature header.')`。返回 401。

**判定**：**BLOCKED** — `ctype_digit()` 强制时间戳是纯整数字符串。

---

### ATK-11 — 头部注入：HMAC 十六进制中的逗号

**攻击**：在 `v1` 值中注入逗号以混淆解析器。

```
X-Webhook-Signature: t=<now>,v1=abc,def
```

**观察结果**：`parseHeader()` 使用限制为 2 的 `explode('=', $chunk, 2)`。头部首先按 `,` 分割（产生 `['t=<now>', 'v1=abc', 'def']`），然后每个 chunk 按 `=` 分割，限制为 2。`def` chunk 变为 `['def', '']`，不覆盖关键内容。`v1` 值为 `abc`，不是有效的 HMAC 十六进制。`hash_equals()` 失败。返回 401。

**判定**：**BLOCKED** — 解析器鲁棒性 + HMAC 长度检查防止注入操控。

---

### ATK-12 — 大型 body：payload 大小攻击

**攻击**：发送数兆字节 body 的 webhook。

**观察结果**：验证器计算 `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)`。`hash_hmac()` 处理任意大输入；输出始终为 64 个十六进制字符。在验证器层没有应用显式大小限制。如果签名有效且时间戳是新鲜的，100 MB 的 body 将被接受。

**判定**：**EXPOSED** — webhook 端点没有请求大小限制。在上游添加请求大小中间件（例如 1 MB 限制）以防止资源耗尽。验证器不应负责大小限制——这是外层中间件层的关注点。

---

## ATK 摘要

| # | 攻击向量 | 判定 |
|---|---|---|
| ATK-01 | 缺少签名头部 | BLOCKED |
| ATK-02 | 篡改签名（1 个字符） | BLOCKED |
| ATK-03 | 使用错误 secret | BLOCKED |
| ATK-04 | 重放攻击（旧时间戳） | BLOCKED |
| ATK-05 | 未来时间戳绕过 | BLOCKED |
| ATK-06 | Body 篡改 | BLOCKED |
| ATK-07 | 格式错误的头部（无时间戳） | BLOCKED |
| ATK-08 | 空服务器 secret（配置错误） | EXPOSED |
| ATK-09 | 空 `v1=` 值 | BLOCKED |
| ATK-10 | 非数字时间戳 | BLOCKED |
| ATK-11 | 通过逗号进行头部注入 | BLOCKED |
| ATK-12 | 大型 body / 资源耗尽 | EXPOSED |

**生产前需要修复的真实漏洞**：
1. **ATK-08** — 启动时的 fail-closed 空 secret 守卫（`if ($secret === '') throw`）
2. **ATK-12** — webhook 路由上游的请求大小中间件（例如 1 MB 限制）

---

## 设计说明

### 为什么使用 HMAC-SHA256 而不是简单的 bearer token？

Bearer token 只证明发送者知道该 token。HMAC-SHA256 证明发送者知道 secret **并且** body 未被修改——body 完整性是内建的。

### 为什么将时间戳绑定到 HMAC payload？

如果签名只是 `HMAC(body)`，捕获有效请求的攻击者可以无限期重放它。通过签名 `"<timestamp>.<body>"`，每个签名只在 300 秒窗口内有效，且只对其计算的确切 body 有效。

### 为什么使用 `hash_equals()` 而不是 `===`？

PHP 的 `===` 是短路比较：一旦两个字符不同就停止。攻击者可以测量比较两个字符串所需的时间，推断有多少前导字符匹配，从而实现时序预言攻击，逐字节暴力破解 secret。`hash_equals()` 无论字符串在哪里不同都以恒定时间运行。

---

## 相关指南

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — PIN 存储 + 锁定的 `hash_equals()` 和 HMAC-SHA256
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 攻击者思维 ATK 评估模式
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — 作为签名验证补充的限流
