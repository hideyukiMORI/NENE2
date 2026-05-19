# 添加 MCP 工具

本指南展示如何将 NENE2 应用程序的 API 端点公开为 MCP 工具，使 AI 助手（Claude、Cursor 等）能够通过模型上下文协议调用您的 API。

**前提条件**：拥有至少包含一个路由和 `docs/openapi/openapi.yaml` 文件的 NENE2 应用程序。

---

## 概览

NENE2 提供本地 MCP 服务器（`LocalMcpServer`），将 JSON-RPC MCP 消息转换为对 API 的 HTTP 调用。工具目录（`docs/mcp/tools.json`）声明哪些端点作为 MCP 工具公开及其安全级别。

```
AI 助手 → JSON-RPC (stdio) → LocalMcpServer → HTTP → NENE2 应用
```

---

## 1. 添加验证器脚本

在 `composer.json` 中添加：

```json
{
  "require-dev": { "symfony/yaml": "^7.0" },
  "scripts": {
    "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
  }
}
```

安装依赖：`composer require --dev symfony/yaml`

---

## 2. 创建工具目录

创建 `docs/mcp/tools.json`，每个条目对应一个 API 端点。

### 安全级别

| 级别 | 含义 |
|---|---|
| `read` | 无副作用，可安全调用（GET 请求） |
| `write` | 创建或修改数据（POST / PUT / PATCH） |
| `admin` | 管理操作，谨慎使用 |
| `destructive` | 永久删除数据，需明确确认 |

---

## 3. 验证目录

```bash
composer mcp
```

---

## 4. 添加写入工具

写入工具调用 `POST`、`PUT`、`PATCH` 或 `DELETE` 端点。以相同方式添加到目录，仅 `safety` 级别和 `inputSchema` 不同。

---

## 5. 用 JWT 保护写入工具

在环境中设置 `NENE2_LOCAL_JWT_SECRET`。未设置时，写入工具调用返回 MCP 错误。

---

## 6. 启动 MCP 服务器

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8080 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

---

## 7. 测试 MCP 层

直接测试 `LocalMcpToolCatalog`，无需 HTTP 服务器：

```php
$catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');
$tool = $catalog->find('listNotes');
self::assertSame('read', $tool['safety']);
```

---

## 后续步骤

- [添加 JWT 认证](./add-jwt-authentication.md)
- [添加限速](./add-rate-limiting.md)
