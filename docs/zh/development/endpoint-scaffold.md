# 端点脚手架工作流

该工作流确保新的 NENE2 JSON 端点在运行时代码、OpenAPI、测试和可选 MCP 元数据中保持一致。

目前有意保持手动。目的是在添加代码生成器之前明确步骤。

## 定位

端点只有在其行为在所有相关位置都可见时才算完成：

- 运行时路由和处理器
- OpenAPI 路径、响应模式和示例
- 运行时或处理器测试
- 通过 `docs/openapi/openapi.yaml` 的契约测试
- 端点成为工具时的 MCP 目录更新
- 端点改变项目策略或工作流时的文档更新

## 标准步骤

1. 创建或复用一个专注的 GitHub Issue。
2. 在最小合适的处理器边界添加或更新运行时路由。
3. 添加包含 `operationId`、示例、成功模式和 Problem Details 响应的 OpenAPI 路径。
4. 添加或更新接近行为的测试。
5. 首先运行专注测试，然后运行 `docker compose run --rm app composer check`。
6. 如果端点可通过 Docker 访问，运行本地 HTTP 冒烟检查。
7. 仅在当前工作受影响时更新 `docs/todo/current.md`、里程碑文档和 MCP 目录。

## 运行时路由

在当前小型运行时中，示例路由在 `RuntimeApplicationFactory` 中描述。

脚手架示例：

```text
GET /examples/ping
```

返回响应：

```json
{
  "message": "pong",
  "status": "ok"
}
```

此端点用于练习工作流。随着行为增加，应用端点应迁移到委托给用例的薄处理器。

### 路径参数

路由器将匹配的路径参数以命名数组的形式存储在 `Router::PARAMETERS_ATTRIBUTE` 下——而非作为单独的 PSR-7 请求属性设置。

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $request) use ($json): ResponseInterface {
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

写 `$request->getAttribute('id')` 会返回 `null`。始终从 `Router::PARAMETERS_ATTRIBUTE` 读取。

## OpenAPI 要求

每个已发布的 JSON 端点必须包含：

- 稳定的 `operationId`
- 简短的 summary 和安全的 description
- `200` 响应模式和 `ok` 示例
- 适当的 Problem Details 响应（`401`、`413`、`500` 等）
- 仅在对应中间件存在时才添加安全要求

运行时契约测试自动读取 `docs/openapi/openapi.yaml` 并验证 JSON 端点的文档化 `200` 示例。

## 测试要求

首先使用最窄的有用检查：

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

然后运行完整的后端检查：

```bash
docker compose run --rm app composer check
```

对通过 Docker 提供的端点，运行本地冒烟检查：

```bash
curl -i http://localhost:8080/examples/ping
```

## 与 MCP 的关系

如果新端点成为 MCP 工具：

1. 首先添加 OpenAPI 操作。
2. 仅在工具可以安全调用公共 API 边界时，才将 read-only 条目添加到 `docs/mcp/tools.json`。
3. 运行 `docker compose run --rm app composer mcp`。
4. 在认证、授权和审计行为文档化并实现之前，变更、管理和破坏性工具保持超出范围。

## 非目标

- 在手动工作流证明有用之前自动生成端点文件。
- 用魔法控制器发现隐藏路由行为。
- 默认为所有端点更新 MCP 目录。
- 在路由和模式模式稳定之前要求运行时 OpenAPI 验证。

## 相关文档

- 运行时：`src/Http/RuntimeApplicationFactory.php`
- OpenAPI：`docs/openapi/openapi.yaml`
- 运行时契约测试：`tests/OpenApi/RuntimeContractTest.php`
- 请求验证策略：`docs/development/request-validation.md`
- 域层策略：`docs/development/domain-layer.md`
- MCP 工具策略：`docs/integrations/mcp-tools.md`
