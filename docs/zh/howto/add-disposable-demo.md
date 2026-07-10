# 操作指南：添加一次性演示

本指南展示如何为您的产品添加一个**发票风格的一次性演示（disposable demo）**：访问者打开
`GET /demo/{template}`，应用即时创建一个全新的临时组织，用真实感的行业数据进行填充，
建立已认证会话，并 302 重定向进入这个新租户。cron 清扫器会在 TTL 到期后销毁演示组织。
"重置演示"就是再次访问该 URL——每次都会得到一个新组织。

框架模块为 `Nene2\Demo`。它负责与产品无关的编排流程
（门控 → 节流/容量 → 带冲突重试的 slug 分配 → 创建 → 数据填充 → 会话就座）
以及清扫决策（TTL + 超量）。您需要实现四个小型接口，承载所有产品特定的部分。

**前提条件**：一个可运行的 NENE2 应用，具有多租户组织模型
（组织由 slug 标识），并且已有创建和删除组织的方式。

---

## 框架提供的部分 vs. 您需要实现的部分

| 框架（`Nene2\Demo`） | 您（产品方） |
|---|---|
| `StartDisposableDemoHandler` —— HTTP 编排 | `DisposableOrgProvisionerInterface` —— 创建一个演示组织 + 管理员 |
| `DisposableDemoSweeper` —— TTL / 超量决策、`SweepReport` | `DisposableOrgReaperInterface` —— 销毁一个组织**及其子数据** |
| `CountingDemoCapacityGuard` —— 创建时的上限 + 按 IP 节流 | `DemoSessionSeaterInterface` —— 认证交接 + 重定向 |
| `DemoConfig` —— `AppConfig::$demo` 上的类型化 `DEMO_*` 设置 | `DemoDataSeederInterface` —— 行业种子数据 |
| `DemoRouteRegistrar` —— 注册 `GET /demo/{template}` | `DemoTemplateKeyInterface` —— 您的模板枚举 |
| `MinimalDemoErrorPageRenderer` —— 无品牌的浏览器错误页面 | `DemoErrorPageRendererInterface` —— 带品牌的错误页面（可选） |

---

## 1. 配置

`DEMO_*` 变量由 `ConfigLoader` 加载到 `AppConfig::$demo`
（类型化的 `Nene2\Demo\DemoConfig`）——切勿用 `getenv()` 读取它们：

```bash
DEMO_MODE=1            # 严格解析：只有 1/true/yes 会启用；默认关闭
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

当 `DEMO_MODE` 未设置时，端点返回普通的 404——您可以将接线以休眠状态发布，
按部署环境单独启用。

## 2. 定义模板键

一个 string-backed 枚举，列出您可填充的行业预设：

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // URL 中的 {template} 段
    case Seisaku = 'seisaku';

    public function value(): string
    {
        return $this->value;
    }

    public static function tryFromValue(string $value): ?static
    {
        return self::tryFrom($value);
    }
}
```

## 3. 实现 provisioner（创建器）

对您已有的"创建组织"用例做一层薄封装。slug 被占用时抛出
`SlugConflictException`（处理器会用新的随机 slug 重试），
在内部生成临时管理员凭据，并返回管理员的 id——
框架从不通过角色字面量去查找管理员：

```php
final readonly class DemoOrgProvisioner implements DisposableOrgProvisionerInterface
{
    public function __construct(private CreateOrganizationUseCaseInterface $createOrg)
    {
    }

    public function provision(string $slug, string $template): ProvisionedDemoOrg
    {
        try {
            $org = $this->createOrg->execute(null, new CreateOrganizationInput(
                name: $this->companyName($template),
                slug: $slug,
                adminEmail: 'admin@' . $slug . '.demo.local',
                adminPassword: SecureTokenHelper::generate(16),
            ));
        } catch (OrganizationSlugConflictException $e) {
            throw new SlugConflictException($slug, previous: $e);
        }

        return new ProvisionedDemoOrg($org->id, $org->slug, $org->adminUserId);
    }
}
```

## 4. 实现 seeder（数据填充器）

填充内容完全由您决定。有两条硬性规则：

- **通过唯一一个注入的连接写入**——即请求已经在使用的那个 executor。
  对同一数据库开第二个 PDO 会在 SQLite 下死锁（`database is locked`）。
- **每一行都带上显式的 `$orgId`**——演示路由在入口处没有组织上下文，因此
  填充是一次刻意的跨租户写入，目标是您刚创建的组织。这里绝不能依赖
  请求作用域的租户 holder。

```php
final class DemoDataSeeder implements DemoDataSeederInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
        private readonly ClockInterface $clock,
    ) {
    }

    public function seed(int $orgId, DemoTemplateKeyInterface $template): void
    {
        // 插入客户、项目、文档……以 $this->clock->now() 为时间锚点
    }
}
```

将填充数据的日期锚定到注入的时钟（相对的"本月"日期能让演示始终显得新鲜），
并将历史事件钳制到今天为止。

## 5. 实现 seater（会话就座器）

您产品的认证逻辑就在这里，完全隔离。cookie 会话型产品签发限定到新租户的
登录 cookie 并 302 进入 SPA；JWT bearer 型产品则以自己的方式让 SPA 登陆：

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* 限定到该租户的会话 cookie */);
    }
}
```

产品特定的会话语义（例如一次性 cookie——刷新页面就回到登录界面）
应保留在这个类内部——它们绝不能泄漏到编排层。

## 6. 实现 reaper（销毁器）

> **警告**：典型的"删除组织"用例**不会**级联到子表——
> 只删除组织行会让孤儿子数据永远滞留。reaper 负责完整的清除，
> 框架刻意不去猜测您的表结构。

先删除子行（包括只能通过父级到达的孙级数据），然后删除组织，
最后清理数据库之外的残留（文件戳、缓存）。`reap()` 必须**幂等**：
组织已被并发运行清扫过也算成功，而不是错误——
`DisposableDemoSweeper` 依赖这一点，且不会捕获您的异常。

```php
final readonly class DemoOrgReaper implements DisposableOrgReaperInterface
{
    public function reap(int $orgId): void
    {
        foreach (self::CHILD_TABLES as $table) {
            $this->query->execute("DELETE FROM {$table} WHERE organization_id = ?", [$orgId]);
        }

        try {
            $this->deleteOrg->execute(null, $orgId);
        } catch (OrganizationNotFoundException) {
            // 已经不存在（并发清扫）——幂等成功
        }
    }
}
```

## 7. 接线处理器和路由

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // 注入计数——框架对您的租户表结构一无所知。
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // 生产环境要用共享存储！
);

$handler = new StartDisposableDemoHandler(
    $config->demo,
    $guard,
    new DemoOrgProvisioner($createOrg),
    new DemoDataSeeder($query, $clock),
    new DemoSessionSeater(...),
    $problemDetails,
    DemoTemplate::class,
);

(new DemoRouteRegistrar($handler))($router);   // GET /demo/{template}
```

该端点在设计上是公开且无组织上下文的（它的职责就是*创建*组织）。如果您的产品有
租户解析中间件，请将 `/demo/...` 从组织解析中豁免。

> **警告**：[添加速率限制](add-rate-limiting.md)中的注意事项同样适用于
> 该守卫的节流：`InMemoryRateLimitStorage` 不在 PHP-FPM worker 之间共享状态
> （生产环境请使用 Redis/Memcached/DB），并且在反向代理之后要注入一个
> 读取您可信的转发 IP 响应头的 `keyExtractor`——否则所有客户端
> 会共享同一个桶。

守卫节流的默认值是**每个客户端 IP 每小时 30 次演示启动**。调整 `throttleLimit` 时请记住：
这种演示在设计上是一次性的——「重置演示」就是重新点击链接，每次点击都消耗一次启动——
而且办公室和移动运营商的 NAT 会把许多合法访客聚合在同一个 IP 之后。
10 次/小时的限制在生产环境中耗尽了正常使用；不要设得更低。

## 8. 可选：为浏览器错误页面加上品牌

演示启动路由是唯一一条真实的人会在**浏览器**中打开的路由（销售潜在客户点击推荐链接）。
因此处理器会对其错误进行内容协商：当请求的 `Accept` 头包含 `text/html` 时，
4xx/5xx 的 Problem Details JSON 会被替换为您注入的 `DemoErrorPageRendererInterface`
生成的 HTML 页面。默认是随附的 `MinimalDemoErrorPageRenderer`——一张极简、无品牌的
英文卡片——因此开箱即用；替换它即可提供您产品的文案、语言和品牌：

```php
final readonly class BrandedDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        // 每个状态使用固定文案；把 $retryAfterSeconds 转换为「约 N 分钟后重试」。
    }
}

$handler = new StartDisposableDemoHandler(
    // ... 与第 7 步相同 ...
    errorPageRenderer: new BrandedDemoErrorPageRenderer($responseFactory),
);
```

无论接线哪个渲染器，框架都会强制执行传输不变量：页面保留原始错误状态和原始
`Retry-After` 头（429），并被加上 `X-Robots-Tag: noindex`。API 客户端（`Accept` 中
不含 `text/html`）和成功重定向保持逐字节不变。

自定义渲染器的两条硬性规则：

- **绝不把请求输入放进页面。** 接口刻意只接收状态码和重试秒数——所有文案必须由固定文本
  加服务器端计算的数字组成，否则错误页面会变成 XSS 载体。同时要包含
  `<meta name="robots" content="noindex">`，并且不引用任何外部资源。
- **注意 Content-Security-Policy。** 您的应用几乎肯定运行着带有全应用
  `default-src 'self'` 的 `SecurityHeadersMiddleware`，它会**阻止自包含错误页面所需的
  内联 `<style>`/`<script>`**——页面会显示为无样式的裸文本。该中间件只添加缺失的响应头，
  因此请在渲染器的响应上携带页面专用的 CSP：

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'
  ```

  只有当页面确实带有脚本（例如重试倒计时）时才添加 `script-src 'unsafe-inline'`。
  内联许可在这里之所以安全，恰恰是因为页面不包含任何请求输入。随附的渲染器已经带有这个 CSP。

如果您需要的不只是换一个错误页面——额外的关卡、日志、响应后处理——
`DemoRouteRegistrar` 接受任何 PSR-15 `RequestHandlerInterface`，因此您可以用装饰器包装
`StartDisposableDemoHandler`，而不必重新实现路由注册。

## 9. 用 cron 清扫

```php
// tools/sweep-demo.php —— 每小时运行
$sweeper = new DisposableDemoSweeper($config->demo, new DemoOrgReaper(...), new UtcClock());

$rows = $query->fetchAll(
    'SELECT id, created_at FROM organizations WHERE slug LIKE ?',
    [$config->demo->slugPrefix . '%'],
);
$report = $sweeper->sweep(array_map(
    static fn (array $row): DemoOrgRecord => new DemoOrgRecord(
        (int) $row['id'],
        new DateTimeImmutable((string) $row['created_at']),
    ),
    $rows,
));

echo count($report->reapedOrgIds) . " demo orgs swept\n";
```

两个条件组合生效：超过 `DEMO_TTL_HOURS` 的组织过期，且无论年龄如何，只有最新的
`DEMO_MAX_ORGS` 个组织能存活（防失控保险）。清扫器只会看到您传给它的记录——
您查询中的 `LIKE 'demo-%'` 过滤才是保护真实组织的关键，因此绝不要放宽它。

---

## HTTP 表面

| 场景 | 响应 |
|---|---|
| `DEMO_MODE` 关闭 | 404 `not-found`（与不存在路由无法区分） |
| 未知 `{template}` | 404 `not-found` |
| 超过按 IP 节流限制 | 429 `too-many-requests` + `Retry-After` |
| 达到演示组织上限 | 503 `demo-capacity-exceeded` |
| 所有 slug 尝试都冲突 | `SlugConflictException` 逃逸 → 经错误中间件返回 500 |
| 成功 | 由您的 seater 决定（通常为 302 + `Cache-Control: no-store`） |

API 客户端收到 RFC 9457 Problem Details；浏览器客户端（`Accept` 包含 `text/html`）
收到第 8 步的错误页面，状态和 `Retry-After` 保持不变。

## 清扫器已经限制了数量，为什么还要在创建时设守卫？

仅靠清扫只能限制**稳态**数量。在两次清扫之间，爬虫或攻击者可以无限制地扩大
租户表——每次演示启动都会写入一个组织及其完整的种子数据。
`CountingDemoCapacityGuard` 通过在**创建任何东西之前**检查上限和
按客户端的速率来堵住这个缺口。
