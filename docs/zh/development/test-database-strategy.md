# 测试数据库策略

NENE2 的数据库适配器测试默认是确定性的，不需要开发者专用的数据库服务器。

## 默认策略

框架管理的数据库适配器测试首先使用 SQLite 内存数据库。

原因：

- 测试在现有 PHP 容器内运行
- 无需本地 MySQL 或 PostgreSQL 凭证
- 每个测试可以创建自己的模式
- 运行速度足以满足 `composer check`
- 易于检查和理解

专注数据库适配器检查的默认命令：

```bash
docker compose run --rm app composer test:database
```

`composer check` 继续运行包含数据库适配器测试的完整 PHPUnit 套件。

## 测试形式

数据库适配器测试应：

- 在测试内创建模式
- 使用小而确定性的数据
- 避免接近生产环境的凭证
- 避免依赖迁移状态（除非测试明确针对迁移）
- 优先使用类型化配置对象而非原始环境变量
- 将 SQL 预期值放在被测适配器附近

## 外部数据库

对于 SQLite 无法覆盖的适配器行为，可通过 Docker Compose 使用 MySQL 验证。

启动服务并运行可选命令：

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

该路径验证针对真实 MySQL 服务的 PDO MySQL 连接创建、参数化查询执行和事务回滚。

外部数据库测试在 CI 中存在文档化的服务容器和安全凭证之前保持可选状态。它不会阻塞默认的本地 `composer check` 路径。

Docker Compose 默认使用仅限本地的开发凭证。必要时用环境变量覆盖，不要提交真实数据库密钥。

## 迁移测试

迁移测试应与仓库适配器测试分离。

引入迁移测试时，需定义：

- CI 中使用的数据库服务
- 如何在运行间重置模式
- 是否允许数据填充
- 要运行的 Composer 命令
- 迁移有意不可逆时的行为
