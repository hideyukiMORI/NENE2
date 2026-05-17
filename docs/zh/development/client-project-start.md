# 客户端项目启动指南

本指南介绍如何将 NENE2 适配为小型客户端式 API 项目。

有意保持实用和手动。目标是在添加生成器或宽泛的框架便利层之前，使首次项目移交可信。

## 起始点

当项目需要以下内容时使用本指南：

- 运行中的本地 JSON API
- 可早期共享的 OpenAPI 文档
- 一小组经过测试的端点
- 可选的 React 启动器集成
- 通过文档化 API 边界进行安全的本地 MCP 检查
- 基本的机器客户端认证
- 基于 Docker 的数据库验证路径

NENE2 仍是 `0.x` 基础。将公共契约视为有用但仍在形成中。

## 公开实地试验参考沙盒（可选）

在第一个本地里程碑之后，检查保持在文档化脚手架路径上的**完整公开演示**可能有帮助：

- 仓库：[`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial)（基于 NENE2 **`v0.1.1`**）。
- 内容：只读展览形状 JSON API、OpenAPI、PHPUnit、本地 MCP 工具和 Markdown 实地试验笔记。

这**不是**官方产品仓库，也**不意味着认可**任何真实展览。**虚构的沙盒数据**——在将名称或年份视为事实之前，请阅读该项目的 `README.md` 和 `SECURITY.md`。

## 从 `composer require` 开始

如果从头开始新项目而非 fork NENE2 仓库：

```bash
mkdir my-project && cd my-project
composer init --name="vendor/my-project" --no-interaction
composer require hideyukimori/nene2:^0.3
```

然后手动创建最小文件：

**`.env`**
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="My Project"
DB_ADAPTER=sqlite
```

**`public/index.php`** — 使用内置容器的前端控制器：
```php
<?php
declare(strict_types=1);

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeContainerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = (new RuntimeContainerFactory(dirname(__DIR__)))->create();
$psr17     = $container->get(Psr17Factory::class);
$request   = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response  = $container->get(RequestHandlerInterface::class)->handle($request);
$container->get(ResponseEmitter::class)->emit($response);
```

使用 PHP 内置服务器本地提供：
```bash
php -S localhost:8080 -t public
```

### 添加自定义路由

通过 `$routeRegistrars` 传递自定义路由：

```php
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
                $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                return $json->create(['id' => (int) ($params['id'] ?? 0)]);
            });
        },
    ],
))->create();
```

## 首次本地设置

从干净的克隆开始：

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

确认本地 API 和文档：

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

有用的浏览器 URL：

- OpenAPI：`http://localhost:8080/openapi.php`
- Swagger UI：`http://localhost:8080/docs/`

## 重命名项目边界

在添加应用行为之前，首先更新面向项目的元数据：

- `README.md` 项目描述
- 当项目不会继续作为 NENE2 框架包时，更新 `composer.json` 包名和描述
- OpenAPI 的 `info.title`、`info.description` 和 `info.version`
- 不再描述 NENE2 本身的默认示例

## 添加第一个应用端点

对每个已发布的 JSON 端点使用 `docs/development/endpoint-scaffold.md`。

1. 创建或复用专注的 GitHub Issue。
2. 在最小清晰的运行时边界添加路由。
3. 添加 OpenAPI 路径、`operationId`、模式和示例。
4. 添加接近端点行为的运行时测试。
5. 让 `tests/OpenApi/RuntimeContractTest.php` 验证文档化的成功示例。
6. 通过 Docker 运行本地 HTTP 冒烟检查。

## 将 OpenAPI 作为移交契约

OpenAPI 应与端点行为在同一 PR 中更新。

发送移交之前运行：

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## 仅通过 API 边界添加 MCP

1. 添加或确认 OpenAPI 操作。
2. 将 read-only 条目添加到 `docs/mcp/tools.json`。
3. 运行 `docker compose run --rm app composer mcp`。
4. 仅针对本地 API 对本地 MCP 服务器进行冒烟测试。

## 保护机器客户端路径

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

不要提交真实 API 密钥、生成的密钥或本地 `.env` 文件。

## 验证数据库行为

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## 移交清单

在移交客户端式项目之前确认：

- `README.md` 描述项目，而非仅仅是启动器。
- `docs/openapi/openapi.yaml` 与已发布的 JSON 行为匹配。
- Swagger UI 在本地加载。
- 新端点有运行时测试和 OpenAPI 示例。
- 受保护路由文档化了所需凭证而不暴露密钥值。
- MCP 工具（如有）仅调用文档化的 API 边界。
- `docker compose run --rm app composer check` 通过。
- 延迟的工作在 `docs/todo/current.md` 中可见。

## 有用的后续文档

- 域层策略：`docs/development/domain-layer.md`
- 端点脚手架工作流：`docs/development/endpoint-scaffold.md`
- 本地 MCP 服务器指南：`docs/integrations/local-mcp-server.md`
- 认证边界：`docs/development/authentication-boundary.md`
- 测试数据库策略：`docs/development/test-database-strategy.md`
