# 添加 HTML 视图

本指南展示如何使用 `NativePhpViewRenderer` 和 `HtmlResponseFactory` 为 NENE2 应用程序添加服务器渲染的 HTML 响应。

**前提条件**：拥有至少包含一个路由的 NENE2 应用程序。如果还没有，请从 [添加自定义路由](./add-custom-route.md) 开始。

---

## 概览

NENE2 提供零依赖的极简 HTML 渲染层：

| 类 | 作用 |
|---|---|
| `NativePhpViewRenderer` | 在隔离作用域中渲染 `.php` 模板 |
| `HtmlEscaper` | 安全 HTML 输出的值转义（`htmlspecialchars` / UTF-8 / 完整引号） |
| `HtmlResponseFactory` | 将渲染的 HTML 包装为 `text/html; charset=utf-8` PSR-7 响应 |
| `TemplateNotFoundException` | 模板文件缺失或路径无效时抛出 |

HTML 响应与 JSON 端点共存，无需删除任何现有路由。

---

## 1. 创建模板

将原生 PHP 模板文件放在单一根目录下，约定位置为项目根目录的 `templates/`。

```
my-app/
├── templates/
│   ├── layout.php
│   ├── home.php
│   └── notes/
│       ├── index.php
│       └── show.php
```

每个模板接收：

- `$e` — 转义助手（`HtmlEscaper::escape()`）。对用户输入数据务必使用。
- 传递给 `render()` 的 `$data` 数组中的所有键作为独立变量。

> **安全**：对所有来自用户输入、数据库或外部系统的值都调用 `$e(...)`。省略将导致 XSS 漏洞。

---

## 2. 在 ServiceProvider 中注册渲染器

在应用的 `ServiceProviderInterface` 中注册 `NativePhpViewRenderer` 和 `HtmlResponseFactory`：

```php
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;

// 在 ContainerBuilder 中注册
$builder->set(NativePhpViewRenderer::class, static fn () =>
    new NativePhpViewRenderer(dirname(__DIR__) . '/templates')
);
$builder->set(HtmlResponseFactory::class, static fn ($c) =>
    new HtmlResponseFactory($c->get(ResponseFactoryInterface::class), $c->get(StreamFactoryInterface::class), $c->get(NativePhpViewRenderer::class))
);
```

---

## 3. 在处理器中使用 HtmlResponseFactory

```php
final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', [
            'title'       => '欢迎',
            'description' => 'NENE2 驱动的应用程序。',
        ]);
    }
}
```

---

## 4. 注册路由

```php
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. 处理 TemplateNotFoundException

当模板文件不存在、路径为空或路径包含 `..` 时，`NativePhpViewRenderer::render()` 会抛出 `TemplateNotFoundException`。注册域异常处理器以返回有意义的 HTTP 响应。

---

## 6. 混合 HTML 和 JSON 端点

JSON 和 HTML 端点在同一个 `Router` 上共存，无需特殊配置。

---

## 设计说明

- 模板在闭包作用域中运行，无法访问 `$this`。变量通过 `extract()` + `EXTR_SKIP` 注入。
- `HtmlEscaper` 使用 `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`。
- 目录遍历（`../`）在路径解析步骤中被阻止。

参见 [ADR 0009](../adr/0009-v1.0-public-api-scope.md) 了解 `Nene2\View\*` 的稳定性保证。

---

## 后续步骤

- [添加自定义路由](./add-custom-route.md)
- [添加限速](./add-rate-limiting.md)
- [添加 JWT 认证](./add-jwt-authentication.md)
