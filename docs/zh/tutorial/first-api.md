# 10 分钟创建您的第一个 API

本教程将带您从零开始，使用 NENE2 运行一个 JSON API。

完成后，您将拥有：
- 一个响应 HTTP 请求的本地 API
- 一个返回 JSON 的 `/hello` 端点
- 对请求如何流经框架的理解

**适用对象**：熟悉 JavaScript 或 Python 但未接触过 PHP 的开发者。如果您用过 Express 或 FastAPI，这里的概念可以直接对应。

**所需时间**：约 10 分钟。

---

## 所需工具

| 工具 | 用途 | 检查命令 |
|---|---|---|
| PHP 8.4 | 运行应用程序 | `php --version` |
| Composer | PHP 包管理器（类似 npm） | `composer --version` |
| 终端 | 所有命令在此运行 | — |

> **Docker 替代方案**：如果您不想在本地安装 PHP，也可以使用 Docker。
> 请参阅本页底部的 [基于 Docker 的配置](#基于-docker-的配置)。

---

## 第 1 步 — 创建项目目录

```bash
mkdir my-api && cd my-api
```

这等同于 Node.js 项目中的 `mkdir my-app && cd my-app`。

---

## 第 2 步 — 安装 NENE2

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` 是 `npm install` 的 PHP 等价物。它将 NENE2 及其依赖下载到 `vendor/`。

完成后目录结构如下：

```
my-api/
  vendor/        ← 已安装的包（类似 node_modules/）
  composer.json  ← 包元数据（类似 package.json）
  composer.lock  ← 版本锁定（类似 package-lock.json）
```

---

## 第 3 步 — 创建 `.env` 文件

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` 的工作方式与 Node.js 相同。框架在启动时自动读取它。

---

## 第 4 步 — 创建前端控制器

创建 `public/index.php`：

```php
<?php
declare(strict_types=1);

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/hello', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['message' => 'Hello, world!', 'status' => 'ok']);
            });
        },
    ],
))->create();

$request  = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response = $app->handle($request);

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
```

**代码解释**（逐行）：

- `require .../vendor/autoload.php` — 加载所有已安装的包，相当于 JS 中的 `import`
- `$psr17 = new Psr17Factory()` — 创建 HTTP 对象工厂（类似请求/响应构建器）
- `RuntimeApplicationFactory` — 组装完整的中间件管道
- `routeRegistrars` — 在此添加您自己的路由（详见 HOWTO 文档）
- `$router->get('/hello', ...)` — 注册 GET 路由，类似 Express 中的 `app.get('/hello', ...)`
- `$json->create([...])` — 从 PHP 数组构建 JSON 响应

---

## 第 5 步 — 启动服务器

```bash
php -S localhost:8080 -t public
```

这是 PHP 内置的开发服务器，相当于 `npm run dev`——不适用于生产环境，但适合本地开发。

您应该看到：

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## 第 6 步 — 调用 API

打开新终端并运行：

```bash
curl http://localhost:8080/hello
```

您应该看到：

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

也试试内置的健康检查端点：

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

这就是您的第一个 API 在运行。让我们看看还包含了什么。

---

## 第 7 步 — 查看错误处理的实际效果

NENE2 为所有错误返回 [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457)。访问一个不存在的路由：

```bash
curl http://localhost:8080/missing
```

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "instance": "/missing"
}
```

每个错误响应都有 `type` URI、`title` 和 HTTP `status`。这是 NENE2 所有错误响应使用的标准格式。

---

## 刚才发生了什么

以下是 `GET /hello` 的请求流：

```
HTTP 请求
  → RequestIdMiddleware      添加 X-Request-Id 头
  → SecurityHeadersMiddleware 添加 X-Content-Type-Options 等
  → CorsMiddleware           处理 CORS 预检
  → ErrorHandlerMiddleware   捕获未处理的异常
  → RequestSizeLimitMiddleware 拒绝过大的载荷
  → Router                   匹配 /hello → 您的处理器
  → 您的处理器               返回 {"message": "Hello, world!"}
HTTP 响应
```

这些都是自动发生的。您的处理器只需要返回响应——框架处理头部、错误格式化和请求关联。

---

## 下一步

- **添加路径参数**（如 `/hello/{name}`）：参见 [添加自定义路由](../howto/add-custom-route.md)
- **连接数据库**：参见 [添加数据库端点](../howto/add-database-endpoint.md)
- **查看完整 API 文档**：启动服务器并打开 `http://localhost:8080/openapi.php`

---

## 基于 Docker 的配置

如果您偏好 Docker 而非本地 PHP 安装：

```bash
mkdir my-api && cd my-api
```

创建一个最小的 `compose.yaml`：

```yaml
services:
  app:
    image: php:8.4-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html
```

在容器内安装 Composer：

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

按照上述第 3-4 步创建 `.env` 和 `public/index.php`，然后：

```bash
docker compose up -d
curl http://localhost:8080/hello
```

要获取包含 MySQL 支持的更完整 Docker 配置，请参阅 [NENE2 仓库配置指南](../development/setup.md)。
