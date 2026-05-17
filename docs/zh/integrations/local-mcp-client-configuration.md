# 本地 MCP 客户端配置

本指南介绍如何将本地 MCP 客户端连接到 NENE2 的 stdio MCP 服务器。

仅限本地开发。请勿将此配置重用于生产 MCP 部署。

## 前提条件

构建 PHP 镜像并启动本地 API：

```bash
docker compose build app
docker compose up -d app
```

验证 API 是否可访问：

```bash
curl -i http://localhost:8080/health
```

MCP 服务器是 stdio 进程。它不是 HTTP 服务器——需要由 MCP 客户端启动。

## 通用 stdio 配置

对于接受命令、参数和环境变量的 MCP 客户端，使用此格式：

```json
{
  "mcpServers": {
    "nene2-local": {
      "command": "docker",
      "args": [
        "compose",
        "run",
        "--rm",
        "-e",
        "NENE2_LOCAL_API_BASE_URL=http://app",
        "app",
        "php",
        "tools/local-mcp-server.php"
      ]
    }
  }
}
```

使用 `http://app` 的原因：

- MCP 服务器进程在 Docker Compose 的 `app` 容器内运行
- 目标 Web 服务通过 Compose 服务名称可达
- 该容器中的 `localhost` 指向一次性 MCP 容器，而非正在运行的 Web 服务

不要在已提交的 MCP 客户端配置中包含密钥。

## 本地冒烟检查

使用冒烟辅助脚本无需样板代码即可运行完整的 JSON-RPC 序列。

首先需要启动 app 服务：

```bash
docker compose up -d app
```

然后运行辅助脚本：

```bash
# 仅 initialize + tools/list
bash tools/mcp-smoke.sh

# 调用特定工具
bash tools/mcp-smoke.sh getHealth '{}'

# 调用带路径参数的工具（整数字段使用 JSON 数字）
bash tools/mcp-smoke.sh getExhibitionWorkByYearAndId '{"year":2026,"workId":20260101}'
```

必要时覆盖 API 基础 URL：

```bash
NENE2_LOCAL_API_BASE_URL=http://my-api bash tools/mcp-smoke.sh getHealth '{}'
```

**手动替代方案** — 需要更精细控制时，管道传输原始 JSON-RPC 行：

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## 可用工具

首个本地服务器从 `docs/mcp/tools.json` 加载只读工具。

当前示例：

- `getFrameworkSmoke`
- `getHealth`

验证目录：

```bash
docker compose run --rm app composer mcp
```

### 路径参数类型

映射到具有整数参数（如 `{year}`、`{id}`）的 OpenAPI 路径的工具，需要在 `tools/call` 参数中使用 JSON 数字而非字符串。

正确：

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

错误（当模式指定 `integer` 时会被拒绝）：

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

查看 `docs/mcp/tools.json` 中工具的 `inputSchema` 以确认预期类型。

## 安全规则

本地 MCP 客户端允许的操作：

- 调用文档化的本地 HTTP API
- 通过服务器读取已提交的 MCP 元数据
- 使用与 OpenAPI 操作对应的只读工具

本地 MCP 客户端禁止的操作：

- 读取 `.env` 密钥
- 调用生产 API
- 暴露直接的数据库或文件系统访问
- 未经专注的 Issue 和设计就添加写入、管理或破坏性工具
- 提交用户特定的 MCP 客户端配置

## 相关文档

- 本地 MCP 服务器指南：`docs/integrations/local-mcp-server.md`
- MCP 工具策略：`docs/integrations/mcp-tools.md`
- MCP 目录：`docs/mcp/tools.json`
- 客户端项目启动指南：`docs/development/client-project-start.md`
- 认证边界：`docs/development/authentication-boundary.md`
