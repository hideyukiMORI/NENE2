# MCP 工具集成策略

NENE2 的 MCP 工具必须通过文档化的边界暴露应用功能，而非隐藏的数据库或文件系统快捷方式。

## 定位

MCP 集成是兼容 API 的集成层。

默认方向：

- 在实用时从 OpenAPI 推导工具形式
- 从只读检查工具开始
- 将本地开发工具与生产工具分离
- 在变更工具之前要求显式授权和审计策略
- 默认避免从 MCP 工具直接访问数据库

## 工具来源

工具定义的推荐来源：

- 公共 JSON API 的 OpenAPI 操作
- 不使用 HTTP 的内部工具的文档化应用服务
- 仅限本地工作流的显式维护命令

避免创建无法通过正常应用边界执行和验证的 MCP 专用行为。

## 目录

首个机器可读的 MCP 工具目录位于 `docs/mcp/tools.json`。

包含与已发布 OpenAPI 操作对应的只读工具元数据。目录通过以下命令验证：

```bash
docker compose run --rm app composer mcp
```

`composer check` 包含此验证。

## 安全级别

每个 MCP 工具在实现前必须分类：

- `read`：返回而不修改应用状态
- `write`：修改应用状态
- `admin`：修改配置、权限、数据保留或运营状态
- `destructive`：删除数据或执行不可逆操作

首个 MCP 工具应为 `read` 工具。

`write`、`admin` 和 `destructive` 工具需要：

- 文档化的认证和授权行为
- 审计/日志字段
- request id 传播
- 破坏性操作的显式确认行为
- 覆盖失败和权限边界的测试

API 密钥和令牌边界在 `docs/development/authentication-boundary.md` 中定义。

## 本地开发工具

仅限本地的 MCP 工具帮助代理检查开发应用，但范围必须明确限制。

本地工具允许的操作：

- 调用本地 HTTP API
- 读取已提交的文档
- 执行文档化的安全验证命令

本地工具禁止的操作：

- 读取 `.env` 密钥
- 以类似生产行为的方式绕过应用授权
- 在文档化的测试或迁移命令之外修改数据库
- 依赖开发者的私有文件系统布局

## 生产工具

生产 MCP 工具应作为产品功能设计，而非调试快捷方式。

启用生产工具之前，记录：

- 所有者和目的
- 所需凭证或范围
- 允许的环境
- 速率限制或防滥用措施
- 审计字段
- 失败变更的回滚或修复路径

## 与 OpenAPI 对齐

当工具映射到 HTTP API 操作时：

- 使用 OpenAPI 操作的 summary 和模式作为起点
- 将参数名称与 API 契约匹配
- 保留 Problem Details 错误行为
- 在有用时将 request id 包含在日志和返回的元数据中

如果工具需要与当前 API 不匹配的形式，请先更新 API 契约，或记录为何内部服务边界更好。

### 路径参数类型

如果 OpenAPI 路径参数为 `integer` 类型（例如 `{year}`、`{id}`），工具的 `inputSchema` 必须反映该类型：

```json
"inputSchema": {
  "type": "object",
  "properties": {
    "year": { "type": "integer" }
  },
  "required": ["year"]
}
```

LLM 客户端必须将整数路径参数作为 JSON 数字发送，而非字符串：

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

如果模式指定 `"type": "integer"`，发送字符串（`"2026"`）将被适配器验证拒绝。

## 非目标

- 将直接生产数据库工具作为首个 MCP 里程碑。
- 绕过 HTTP/API 测试的 MCP 专用业务行为。
- 在仓库中存储 MCP 凭证。
- 在认证、授权和审计策略存在之前暴露破坏性工具。
