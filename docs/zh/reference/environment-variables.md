# 环境变量

NENE2 识别的所有环境变量。
请在 `.env`（由 phpdotenv 加载）中设置，或在启动服务器前导出。

## 应用程序

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `APP_ENV` | string | `local` | 运行环境。可选值：`local`、`test`、`production`。 |
| `APP_DEBUG` | boolean | `false` | 启用调试输出。仅在开发环境设为 `true`。 |
| `APP_NAME` | string | `NENE2` | 日志输出中使用的应用名称。不能为空。 |

## 认证

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(空 — 禁用)* | 机器客户端端点 `X-NENE2-API-Key` 请求头所需的 API 密钥。留空则禁用机器密钥路径。 |
| `NENE2_LOCAL_JWT_SECRET` | string | *(空 — 禁用)* | 本地 MCP 服务器用于保护写工具的 HMAC-HS256 密钥。留空则只读工具无需认证。 |

## 本地 MCP 服务器

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(必填)* | MCP 服务器代理 API 调用时使用的基础 URL（如 `http://app`）。在 Docker Compose 环境中运行时必填。 |

## 数据库

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `DATABASE_URL` | string | *(空 — 使用 `DB_*`)* | 完整数据库连接 URL。非空时覆盖所有 `DB_*` 变量。 |
| `DB_ADAPTER` | string | `mysql` | 数据库驱动。可选值：`sqlite`、`mysql`。 |
| `DB_HOST` | string | `127.0.0.1` | 数据库主机名或 IP。 |
| `DB_PORT` | integer | `3306` | 数据库端口（1–65535）。 |
| `DB_NAME` | string | `nene2` | 数据库名称。 |
| `DB_USER` | string | `nene2` | 数据库用户名。 |
| `DB_PASSWORD` | string | *(空)* | 数据库密码。 |
| `DB_CHARSET` | string | `utf8mb4` | 数据库字符集。 |

::: warning 切勿提交密钥
不要将包含密码、API 密钥或 JWT 密钥的 `.env` 文件提交到版本控制。
:::
