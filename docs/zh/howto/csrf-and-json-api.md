# CSRF 与 JSON API

## CORS ≠ CSRF 防护

这是 web API 开发中最常见的安全误解之一。

**CORS**（跨源资源共享）控制浏览器是否允许某个来源的 JavaScript *读取*来自另一个来源的响应。服务器添加 `Access-Control-Allow-Origin` 头；浏览器执行该策略。

**CSRF**（跨站请求伪造）是一种攻击，恶意页面欺骗受害者的浏览器向受信任的站点发送状态变更请求——使用受害者的会话 cookie。

NENE2 的 `CorsMiddleware` 处理 CORS。它**不会**阻止来自未知来源的请求。带有 `Origin: https://evil.example.com` 的请求会通过并原封不动地到达你的处理器——这是预期行为。CORS 是一种浏览器保护机制，限制的是 *JavaScript 能读取什么*，而非*服务器接受什么*。

```
# 这些请求都会到达你的处理器——CorsMiddleware 不会阻止它们
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# （无 Origin 头——例如服务器间调用）
```

## 为什么 JSON API 比基于表单的 API 对 CSRF 更有抵抗力

经典 CSRF 利用 HTML 表单（`<form method="POST">`）。浏览器发送带有 `Content-Type: application/x-www-form-urlencoded` 或 `multipart/form-data` 的表单提交——浏览器会自动包含会话 cookie。

带有 `Content-Type: application/json` 的请求在 CORS 规范下**不是"简单请求"**。浏览器会先发送预检 `OPTIONS` 请求。如果你的 CORS 配置没有列出攻击者的来源，浏览器会阻止预检——实际请求永远不会到达。

但是，**这只保护基于浏览器的攻击**。服务器或带显式头的 `fetch()` 调用可以不受限制地向你的 API 发送 `Content-Type: application/json`。CORS 预检由浏览器强制执行，而非服务器。

## 真正的防护：Bearer JWT

NENE2 的标准认证在 `Authorization` 头中使用 Bearer JWT：

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

CSRF 攻击通过滥用 cookie 起作用——浏览器在跨站请求中自动附加 cookie。`Authorization` 头**永远不会**自动发送。恶意页面无法包含受害者的 JWT，因为 `https://evil.example.com` 上的 JavaScript 无法读取 `https://app.example.com` 的令牌。

如果你使用 Bearer JWT 认证并且从不将令牌放在 cookie 中，你在设计上就不容易受到 CSRF 攻击。不需要额外的 CSRF 令牌或 `SameSite` 属性。

## 如果你使用基于 cookie 的会话

如果你的应用使用 `Set-Cookie` 进行会话管理（而非 Bearer JWT），你需要明确的 CSRF 防护：

### 方案一：SameSite cookie（最简单）

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` 阻止浏览器在跨站请求中包含 cookie。`SameSite=Lax` 也是一个合理的默认值，仍然阻止跨站 `POST`。

### 方案二：Origin 头校验中间件

拒绝 `Origin` 不在允许列表中的请求：

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // 非浏览器调用（curl、服务器间）没有 Origin——允许通过
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // 返回 403 Problem Details 响应
            // ...
        }

        return $handler->handle($request);
    }
}
```

在中间件栈中的 CORS 之后注册（参见 CLAUDE.md 第 5 节了解排序）。

### 方案三：CSRF 令牌

生成每会话令牌，在服务器端存储，作为隐藏字段包含在表单中，并在每个状态变更请求上验证。这是传统方法，但增加了复杂性。

## 总结

| 场景 | CSRF 风险 | 建议缓解措施 |
|------|---------|------------|
| `Authorization` 头中的 Bearer JWT | 无——头不会自动发送 | 无需操作 |
| Cookie 会话，SameSite=Strict | 极低 | 保持 `SameSite=Strict` |
| Cookie 会话，无 SameSite | 高 | 添加 `SameSite` 或 Origin 执行 |
| 自定义头中的 API 密钥 | 无——自定义头不会自动发送 | 无需操作 |

最简单的方法：使用 NENE2 内置的 Bearer JWT 认证，避免为 API 端点使用基于 cookie 的会话。
