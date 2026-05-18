# 添加健康检查

本指南演示如何使用 `HealthCheckInterface` 为 `GET /health` 端点添加依赖项健康检查。

**前提条件**：您已有一个可运行的 NENE2 应用。如果还没有，请先阅读[教程](../tutorial/first-api.md)。

---

## 健康检查的工作原理

`GET /health` 始终返回基础响应体：

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

当您注册 `HealthCheckInterface` 的实现类后，该端点会增加一个 `checks` 映射：

- 所有检查通过 → `200 OK`，`"status": "ok"`，每个检查显示 `"ok"`
- 任意检查失败 → `503 Service Unavailable`，`"status": "degraded"`，失败的检查显示 `"error"`

---

## 快速开始

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'cache'; }
    public function check(): bool { return $this->ping(); }
    private function ping(): bool { return true; }
}

$psr17 = new Psr17Factory();
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

正常响应（200 OK）：
```json
{ "service": "NENE2", "status": "ok", "timestamp": "...", "checks": { "cache": "ok" } }
```

降级响应（503 Service Unavailable）：
```json
{ "service": "NENE2", "status": "degraded", "timestamp": "...", "checks": { "cache": "error" } }
```

---

## 使用 DatabaseHealthCheck 参考实现

`src/Example/Health/DatabaseHealthCheck` 是一个现成的 PDO 数据库连接检查实现。
它位于 `src/Example/` 中——请将其复制并适配到您自己的项目中。

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

> **注意**：`DatabaseHealthCheck` 位于 `src/Example/` 中——这是一个参考实现，
> 并非稳定的 API 接口。请将其复制到您的应用中并根据需要进行调整。

---

## 多个健康检查

可传入任意数量的检查项。任何一项失败都会导致整体状态降级。

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

---

## 处理检查中的异常

如果 `check()` 抛出异常，框架会将其视为 `false`——状态变为 `"degraded"`，检查显示 `"error"`。
您无需在 `check()` 内部捕获异常。

---

## 下一步

查看 [HTTP 端点](../reference/http-endpoints.md) 了解完整的 `/health` 响应模式，
或查看[添加速率限制](./add-rate-limiting.md)了解请求速率保护功能。
