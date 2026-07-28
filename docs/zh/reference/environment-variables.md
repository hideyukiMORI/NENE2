# 环境变量

NENE2 识别的所有环境变量。
请在 `.env`（由 phpdotenv 加载）中设置，或在启动服务器前导出。

## 应用程序

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `APP_ENV` | string | `local` | 运行环境。可选值：`local`、`test`、`production`。 |
| `APP_DEBUG` | boolean | `false` | 启用调试输出。仅在开发环境设为 `true`。 |
| `APP_NAME` | string | `NENE2` | 日志输出中使用的应用名称。不能为空。 |
| `PROBLEM_DETAILS_BASE_URL` | string | `https://nene2.dev/problems/` | 拼接到 Problem Details `type` 标识符前缀的基础 URL。在自定义域名下提供自定义问题类型时覆盖此值。 |

## 认证

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(空 — 禁用)* | 机器客户端端点 `X-NENE2-API-Key` 请求头所需的 API 密钥。留空则禁用机器密钥路径。 |
| `NENE2_LOCAL_JWT_SECRET` | string | *(空 — 禁用)* | `LocalBearerTokenVerifier` 使用的 HMAC-HS256 密钥。启用 `GET /examples/protected` 的 Bearer JWT 校验，并保护本地 MCP 服务器的写工具。留空则禁用 JWT 认证，仅允许只读 MCP 访问。通过 `Nene2\Auth\GuardedJwtSecretResolver` 解析时，除非设置了下面的开发密钥 opt-in，否则空值会 fail closed（生产环境绝不允许）。 |
| `NENE2_ALLOW_DEV_SECRET` | boolean（严格） | `false` | `Nene2\Auth\GuardedJwtSecretResolver` 读取的开发密钥 opt-in（通过 `AppConfig::$allowDevSecret` 暴露）。**仅**接受 `1`、`true` 或 `yes`（不区分大小写、去除首尾空白）；任何其他值——包括拼写错误——都视为未启用。当 `local`/`test` 环境中未设置 `NENE2_LOCAL_JWT_SECRET` 时，此项允许使用产品注入的开发密钥。**在生产环境中被忽略**——生产环境始终 fail closed。参见 [ADR 0013](../adr/0013-guarded-jwt-secret-resolution.md)。 |

## 本地 MCP 服务器

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(必填)* | MCP 服务器代理 API 调用时使用的基础 URL（如 `http://app`）。在 Docker Compose 环境中运行时必填。 |

## 数据库

| 变量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `DATABASE_URL` | string | *(空 — 使用 `DB_*`)* | 完整数据库连接 URL。非空时覆盖所有 `DB_*` 变量。 |
| `DB_ADAPTER` | string | `mysql` | 数据库驱动。可选值：`sqlite`、`mysql`、`pgsql`（实验性——参见[使用 PostgreSQL](../howto/use-postgresql.md)）。 |
| `DB_HOST` | string | `127.0.0.1` | 数据库主机名或 IP。**SQLite 不使用此字段。** 在 Docker Compose 中，`compose.yaml` 会为 `app` 服务将其覆盖为 `mysql`。 |
| `DB_PORT` | integer | `3306` | 数据库端口（1–65535）。**SQLite 不验证此字段。** |
| `DB_NAME` | string | `nene2` | 数据库名称。SQLite 时填写文件路径（如 `/tmp/myapp.sqlite`）。 |
| `DB_USER` | string | `nene2` | 数据库用户名。**SQLite 不使用此字段。** |
| `DB_PASSWORD` | string | *(空)* | 数据库密码。 |
| `DB_CHARSET` | string | `utf8mb4` | 数据库字符集。**SQLite 不使用此字段。** |
| `DB_ENV` | string | `local` | Phinx 迁移环境名称（参见 `phinx.php`）。 |


### SQLite 适配器

当 `DB_ADAPTER=sqlite` 时，只需要 `DB_NAME`（文件路径）。`DB_HOST`、`DB_USER` 和 `DB_CHARSET` 不会被验证，无需设置。

```dotenv
DB_ADAPTER=sqlite
DB_NAME=/tmp/myapp.sqlite
```

对于内存 SQLite（在测试中有用），使用 `DB_NAME=:memory:`。

::: warning 切勿提交密钥
不要将包含密码、API 密钥或 JWT 密钥的 `.env` 文件提交到版本控制。
:::
