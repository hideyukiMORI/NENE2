# 在剥离 `Authorization` 的代理后面恢复 Bearer 认证

某些共享主机的前置代理会在请求到达 PHP 之前删除标准的 `Authorization` 头
（已在 HETEML 类主机的生产环境中实际观察到）。自定义头可以通过，`Authorization`
却不行 —— 因此常见的补救技巧也全部失效：

- `.htaccess` 的 `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` ——
  无效，Apache 根本看不到这个头；
- `CGIPassAuth on` —— 同样的原因。

结果是：即使浏览器发送了完全有效的令牌，所有受 Bearer 保护的端点都会返回
401 `missing_token`。

NENE2 内置了标准的两段式解决方案（见 ADR 0019）：

1. **前端**：`@hideyukimori/nene2-client`（≥ 1.1.0）在每个请求上除标准头之外，
   还将令牌镜像到 `X-Authorization: Bearer <token>`。
2. **后端**：`Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` **仅在
   `Authorization` 不存在或为空时**采用该镜像。能正常传递标准头的主机
   完全不受影响（逐字节不变）。

---

## 在默认管道中启用

`RuntimeApplicationFactory` 上的一个 opt-in 标志：

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // 默认关闭
))->create();
```

启用后，回退在认证阶段的最前端运行 —— 在机器 API 密钥检查之前、在任何注入的
认证中间件之前 —— 因此所有读取凭据的中间件都能看到已恢复的头。它与 HTTP
方法和路径无关。

## 或者手动接线

在手工组装的管道中，将它放在认证中间件之前的任意位置：

```php
$stack = [
    // ... request id、日志、安全头、CORS、错误处理 ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

在 PSR-15 管道之外，同样的变换可通过静态助手使用：

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## 什么情况下绝不能启用

启用回退后，`X-Authorization` 在凭据意义上与 `Authorization` 等价。对于*意外*
剥离该头的主机，这正是所需的 —— 但对于上游*有意*剥离它的部署，这恰恰是错误的：

- 网关自身执行认证并向后端转发可信身份；
- WAF 过滤来自不可信客户端的入站凭据。

在这些配置中，镜像会变成客户端可控的绕过通道。要么保持标志关闭，要么让上游
同时剥离 `X-Authorization`。

另外，在访问日志和中间代理中，请以与 `Authorization` 相同的保密级别对待
`X-Authorization`。

## 备注

- 头名称是**固定的**（`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`，
  即 `X-Authorization`）。这是与前端客户端之间的全舰队接线契约，不是可调参数。
- 镜像值原样采用（包含 `Bearer <token>`）。令牌校验仍然完全由你的认证中间件
  负责 —— 无效的镜像与无效的标准头以完全相同的方式失败。
- 优先级始终是：非空的 `Authorization` 获胜；仅当标准头不存在或为空时才查询镜像。
