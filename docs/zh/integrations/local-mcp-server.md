# 本地 MCP 服务器集成

本地 MCP 服务器集成允许代理通过文档化的边界检查和验证 NENE2。

这是一种开发便利工具，而非生产后门。

## 定位

本地 MCP 服务器可以向开发者的本地 NENE2 检出暴露只读检查工具和安全验证命令。

使用：

- 公开的本地 HTTP API
- 已提交的文档
- `docs/mcp/tools.json`
- 文档化的安全本地命令

## 首个本地服务器

NENE2 包含一个仅限本地的 stdio MCP 服务器：

```bash
docker compose run --rm app php tools/local-mcp-server.php
```

默认调用 `http://localhost:8080` 上的本地 API。必要时在仓库外覆盖基础 URL：

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://localhost:8080 app php tools/local-mcp-server.php
```

在 Docker 中针对 Compose `app` 服务运行服务器时：

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

### write 工具的数据库前提条件

Read 工具（`getHealth`、`listExampleNotes`、`getExampleNoteById` 等）仅需要 `app` 容器。

Write 工具（`createExampleNote`、`updateExampleNoteById`、`deleteExampleNoteById`）调用持久化到数据库的端点。在调用 write 工具之前，启动 MySQL 并应用迁移：

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

服务器支持的方法：

- `initialize`
- `tools/list`
- `tools/call`

工具从 `docs/mcp/tools.json` 加载。只读（`safety: read`）和写入（`safety: write`）的 OpenAPI 对应工具都被暴露。

Read 工具（`getHealth`、`getFrameworkSmoke`、`listExampleNotes`、`getExampleNoteById`）映射到 HTTP GET。参数成为路径参数或查询字符串值。

Write 工具（`createExampleNote`、`updateExampleNoteById`、`deleteExampleNoteById`）分别映射到 HTTP POST、PUT 和 DELETE。

## 不应使用的内容

- 直接访问生产数据库
- 原始 `.env` 密钥读取
- 用户的私有文件系统路径
- 无法通过正常边界测试的隐藏应用行为

## 本地工具允许的操作

- 读取已提交的 MCP 目录
- 调用 `http://localhost:8080/` 和其他文档化的本地 API 路由
- 从 HTTP 响应中返回 `X-Request-Id` 元数据
- 执行 `docs/integrations/local-ai-commands.md` 中文档化的验证命令

## 工具形式

本地工具应在实用时映射到现有目录或 OpenAPI 操作。

推荐元数据：

- 工具名称
- 安全级别（`read`、`write`、`admin`、`destructive`）
- 源操作或命令
- 所需范围（如有）
- 工具是否调用 HTTP
- 工具是否返回 request id 元数据

`admin` 和 `destructive` 工具超出当前本地 MCP 服务器指南的范围。

### 整数路径参数

如果工具映射到具有 `{year}` 或 `{id}` 等整数参数的 OpenAPI 路径，则在 `inputSchema` 中将其声明为 `"type": "integer"`，并在 `tools/call` 参数中以 JSON 数字形式传递。

## HTTP 行为

当本地 MCP 工具调用 HTTP API 时：

- 使用配置的本地 API 基础 URL
- 为 JSON API 发送 `Accept: application/json`
- 保留 Problem Details 错误，不重写
- 如果存在，返回或记录 `X-Request-Id` 响应头
- 不在返回的元数据中包含凭证

## 安全命令

本地命令工具应限于文档化的检查：

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

安装依赖、修改数据库、标记发布、合并 PR 或修改 git 历史的命令需要专注的 Issue 和用户明确意图。

## 生产边界

生产 MCP 工具应作为具有认证、授权、审计和运营所有权的产品功能来设计。

不要将本地 MCP 服务器配置重用为生产配置。
