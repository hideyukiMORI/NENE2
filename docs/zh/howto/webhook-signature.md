# Webhook 签名验证

从外部服务（Stripe、GitHub 等）接收 webhook 时，在处理 payload 之前始终验证签名。这证明 webhook 来自预期的发送方，且 body 未被篡改。

## 签名头部格式

使用 Stripe 兼容格式：

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

签名 payload 是 `"<timestamp>.<rawBody>"`——时间戳包含在签名内容中，而不仅仅在头部。这将时间戳与 body 绑定：如果攻击者更改时间戳以绕过过期检查，签名就会失效。

## 关键规则：使用 `hash_equals()`，永远不用 `===`

```php
// ❌ 存在时序攻击漏洞
if ($expectedSig === $receivedSig) { ... }

// ✅ 恒定时间比较——使用此方式
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**`===` 为什么危险：** PHP 的 `===` 在第一个不匹配字符处短路。能够发出大量请求并测量响应时间的攻击者可以了解其猜测与预期签名匹配的字符数——逐字节——并暴力破解 secret。这是时序攻击。

`hash_equals()` 无论字符串在哪里不同都始终比较所有字符，因此响应时间不会泄露任何关于 secret 的信息。它从 PHP 5.6 起就已存在。

这个 bug 无法被 PHPStan、静态分析工具或标准测试检测到。代码审查是唯一的门控。

## 实现

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // 5 分钟重放窗口

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException 如果缺少、格式错误、过期或不匹配
     */
    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);
        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        if (!hash_equals($expectedSig, $receivedSig)) { // ← 恒定时间
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** 为出站 webhook（和测试）生成头部值。 */
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    /** @return array{timestamp: int, signature: string} */
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

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }
}
```

## 控制器集成

在任何 JSON 解析之前读取原始 body——签名是对原始字节计算的：

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create(
            $request,
            'invalid-signature',
            'Invalid webhook signature.',
            401,          // 401：发送方身份无法验证
            $e->getMessage(),
        );
    }

    // 验证后安全解析
    $body = json_decode($rawBody, true);
    // ...
}
```

返回 401（而非 403）：发送方身份无法验证，这是认证失败，而非授权失败。

## Secret 管理

将 webhook secret 存储在环境变量中，永远不写入代码：

```php
// 在配置加载器中
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## 重放攻击防护

5 分钟时间戳窗口（`TOLERANCE_SECONDS = 300`）意味着：

- 拦截有效 webhook 的攻击者无法在 5 分钟后重放它。
- 发送方和接收方之间的实际时钟偏差（通常 < 30 秒）是可以接受的。
- `abs(time() - $timestamp)` 处理过去和未来的时间戳，因此任一方向的轻微时钟漂移都可接受。

## Secret 轮换

在 secret 轮换期间，在过渡期内接受来自旧 secret 和新 secret 的签名：

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets 当前 secret 在前，上一个 secret 在后 */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // 第一个匹配获胜
            } catch (SignatureException) {
                // 尝试下一个 secret
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## 测试

`WebhookVerifier` 的 `sign()` 方法使构建已签名的测试请求变得容易：

```php
private function signedPost(array $payload, int $timestamp): ResponseInterface
{
    $rawBody   = json_encode($payload, JSON_THROW_ON_ERROR);
    $verifier  = new WebhookVerifier('test-secret');
    $sigHeader = $verifier->sign($rawBody, $timestamp);

    $stream  = Stream::create($rawBody);
    $request = (new ServerRequest('POST', '/webhook'))
        ->withHeader('X-Webhook-Signature', $sigHeader)
        ->withBody($stream);
    return $this->app->handle($request);
}

// 测试重放防护
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// 测试篡改 body
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## 代码审查清单

- [ ] 签名比较使用 `hash_equals()`，而非 `===` 或 `==`
- [ ] 时间戳包含在签名 payload 中（`"<t>.<body>"`），而非仅在头部
- [ ] 强制执行时间戳窗口（`abs(time() - $timestamp) > TOLERANCE`）
- [ ] 在解析之前读取原始 body；签名对原始字节验证
- [ ] Webhook secret 来自环境变量，而非硬编码
- [ ] 签名失败返回 401（而非 403 或 400）
- [ ] 错误消息不暴露 secret 或预期签名值
- [ ] 测试涵盖：有效签名、错误 secret、篡改 body、过期时间戳、缺少头部
