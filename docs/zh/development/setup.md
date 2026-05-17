# 本地安装指南

本指南介绍如何在本地环境中从全新克隆到运行 API 完成 NENE2 的安装配置。

## 前提条件

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)（或 Docker Engine + Compose 插件）
- Git

无需在宿主机安装 PHP、Node.js 或 MySQL。所有运行时依赖都在 Docker 内运行。

## 1. 克隆并配置

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

打开 `.env` 并根据需要调整值。默认值无需修改即可用于本地开发。

主要环境变量：

| 变量 | 默认值 | 用途 |
|---|---|---|
| `APP_ENV` | `local` | 运行时环境 |
| `NENE2_MACHINE_API_KEY` | *（空）* | 本地开发中留空以禁用机器客户端认证 |
| `DB_ADAPTER` | `mysql` | `sqlite` 或 `mysql` |
| `DB_HOST` | `mysql` | 与 Docker Compose 服务名称匹配 |

## 2. 构建并安装

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. 运行后端检查

```bash
docker compose run --rm app composer check
```

该命令依次运行 PHPUnit、PHPStan、PHP-CS-Fixer、OpenAPI 验证和 MCP 目录验证。全新克隆后应全部通过。

## 4. 启动 Web 服务器

```bash
docker compose up -d app
```

验证运行状态：

```bash
curl -i http://localhost:8080/health
```

预期响应：

```json
{"status":"ok","service":"NENE2"}
```

其他有用的本地端点：

| URL | 描述 |
|---|---|
| `http://localhost:8080/` | 框架信息 |
| `http://localhost:8080/health` | 健康检查 |
| `http://localhost:8080/examples/ping` | Ping 示例 |
| `http://localhost:8080/examples/notes/{id}` | 按 ID 获取笔记（需要数据库） |
| `http://localhost:8080/openapi.php` | 原始 OpenAPI JSON |
| `http://localhost:8080/docs/` | Swagger UI |

## 5. 停止服务器

```bash
docker compose down
```

## 可选：MySQL 数据库设置

默认测试套件使用内存 SQLite。如需验证 MySQL 适配器或对写操作进行冒烟测试：

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

## 可选：机器客户端认证

`/machine/health` 端点需要 API 密钥。本地测试方法：

1. 在 `.env` 中设置 `NENE2_MACHINE_API_KEY=local-dev-key`。
2. 重启 app 服务：`docker compose up -d app`
3. 调用受保护的端点：

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

## 可选：前端设置

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

## 可选：本地 MCP 服务器

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## 可选：在日志中验证 Request ID

每个请求都会生成一个 `X-Request-Id`，并在响应头中返回，同时附加到每条 Monolog 日志记录。

1. 启动 app：`docker compose up -d app`
2. 发送请求：
   ```bash
   curl -i http://localhost:8080/health
   # 在响应头中查找 X-Request-Id
   ```
3. 查看结构化日志输出：
   ```bash
   docker compose logs app
   # 每行 JSON 日志包含 "extra":{"request_id":"<id>"}
   ```

您也可以提供自己的 ID：
```bash
curl -i -H 'X-Request-Id: my-trace-id' http://localhost:8080/health
```

## 故障排除

**全新克隆时 `composer check` 失败**
先运行 `docker compose run --rm app composer install`。`vendor/` 目录未被提交。

**端口 8080 已被占用**
停止占用它的进程，或修改 `compose.yaml` 中的端口映射：
```yaml
ports:
  - "8081:80"   # 使用 8081 代替
```

**迁移时 MySQL 连接被拒绝**
`mysql` 容器在 `docker compose up -d mysql` 后需要几秒才能就绪。等待片刻后重试。
