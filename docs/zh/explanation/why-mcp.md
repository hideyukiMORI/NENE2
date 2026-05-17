# 为什么选择 MCP 作为 AI 集成边界？

NENE2 通过 Model Context Protocol（MCP）与 AI 代理集成，而非给予代理直接的数据库或文件系统访问权限。本页解释这一设计决策。

## 边界的结构

```
AI 代理（Claude、Cursor 等）
    │  MCP stdio
    ▼
local-mcp-server.php          ← NENE2 的 MCP 服务器
    │  HTTP
    ▼
NENE2 API（PSR-7 / OpenAPI）   ← 与浏览器使用相同的端点
    │  PDO
    ▼
数据库
```

AI 代理永远不会直接访问数据库。所有操作都通过带有请求验证、身份验证和结构化错误响应的文档化 HTTP 端点进行。

## 为什么不让代理直接查询数据库？

### 1. API 契约是真相的来源

OpenAPI 文档描述了哪些操作存在、接受哪些输入、返回哪些输出。SQL 查询绕过了这个契约。

### 2. 授权存在于 API 层

API 密钥认证、CORS 策略和请求大小限制在 PSR-15 中间件中执行。直接数据库连接绕过所有这些。

### 3. 结构化错误帮助代理恢复

当 API 调用失败时，代理收到带有机器可读 `type` 和结构化 `errors` 的 Problem Details 响应。

### 4. 相同端点服务所有客户端

MCP 服务器调用与浏览器、测试套件或 curl 命令相同的路由，这意味着 OpenAPI 测试验证 MCP 可访问的行为。

## 工具安全级别

| 级别 | 示例 | 要求 |
|-----|------|------|
| `read` | `getHealth`、`getNote` | 仅需 API 密钥 |
| `write` | `createNote`、`updateNote` | 同上 |
| `admin` | 假设的角色更改 | 明确确认步骤 |
| `destructive` | 批量删除 | 超出本地开发范围 |
