# 为什么选择 PSR 标准？

NENE2 构建在 PSR-7、PSR-15 和 PSR-17 之上，而非自定义 HTTP 抽象。本页解释其原因。

## 这些标准涵盖的内容

| 标准 | 定义内容 |
|------|---------|
| PSR-7 | `RequestInterface`、`ResponseInterface`、`StreamInterface` — HTTP 消息的形状 |
| PSR-15 | `MiddlewareInterface`、`RequestHandlerInterface` — 中间件和处理器的组合方式 |
| PSR-17 | 创建 PSR-7 对象的工厂接口 |

## 为什么不使用自定义抽象？

自定义 `Request` 类写起来很快且易于控制，但代价会在后期显现：

- 每个新的 HTTP 库都需要自定义适配器。
- 为某个项目编写的中间件无法移植到另一个项目。
- 测试需要运行中的 HTTP 服务器或自定义类本身。

PSR-7 对象是不可变的值对象。接受 `ServerRequestInterface` 并返回 `ResponseInterface` 的处理器对调用它的框架没有任何假设。

## 为什么使用不可变消息？

PSR-7 消息是不可变的：`withHeader()`、`withBody()` 等方法返回新实例而不是修改现有实例。这消除了中间件静默修改后续处理器检查的请求这类 bug。

```php
// 每个中间件获得干净的副本 — 原始请求不变
$request = $request->withAttribute('request_id', $id);
```

## 为什么使用 PSR-15 中间件？

PSR-15 用单一方法定义中间件契约：

```php
public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $next
): ResponseInterface
```

这意味着：

- 任何 PSR-15 中间件都可以插入任何 PSR-15 管道。
- 管道顺序是显式代码，而非隐藏的框架生命周期。
- 单元测试中间件只需要模拟 `RequestHandlerInterface`，无需运行中的服务器。

## 具体包的选择

NENE2 使用 **Nyholm PSR-7** 作为消息对象，使用 **Relay** 作为中间件调度器（见 ADR 0001）。这些是实现标准而不添加框架特定 API 的轻量级包。

## 权衡

| 优点 | 代价 |
|-----|------|
| 可互操作的中间件 | 比流畅的自定义 API 更冗长 |
| 不可变消息减少 bug | 每次 `with*` 调用都创建对象 |
| 无需服务器即可测试 | 需要理解 PSR 接口 |
