# ETag 与条件请求

> **FT 参考**：FT307（`NENE2-FT/etaglog`）——ETag 条件请求：`If-None-Match`→304，`If-Modified-Since`→304，`If-Match`→412（过期）/ 428（缺失），通配符 `If-Match: *` 通过，每次更新后 ETag 变化，15 个测试全部通过。

ETag 让客户端避免重新下载未更改的内容，并在写入前检测过期状态。NENE2 为最常见的模式提供了两个辅助工具。

| 场景 | 请求头 | 辅助工具 | 匹配时 |
|------|--------|---------|--------|
| 条件 GET | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| 条件写入 | `If-Match` | `ConditionalWriteHelper` | 允许写入继续 |
| 无头部的写入 | — | `ConditionalWriteHelper` | 428 Precondition Required |
| 过期写入 ETag | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## ETag 生成

从资源内容生成强 ETag，格式为带双引号的 MD5：

```php
final readonly class Article
{
    public function etag(): string
    {
        // RFC 9110 要求双引号——缺少双引号会导致 If-None-Match 比较始终失败
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

将 ETag 生成集中在一处（实体上的方法），这样修改算法（例如改为 SHA-256）只需编辑一个地方。

## 条件 GET——304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // 当 If-None-Match 与当前 ETag 匹配时返回 304 响应。
    // 当必须发送完整 200 响应时返回 null。
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` 评估两个请求头：
- `If-None-Match`：精确 ETag 匹配 → 304
- `If-Modified-Since`：字符串比较 `$ifModifiedSince >= $lastModified` → 304

始终在 `check()` 调用和 `withHeader('ETag', $etag)` 调用中使用相同的 `$etag` 值。分别生成它们有漂移的风险。

### Last-Modified 格式

`If-Modified-Since` 检查是**字符串比较**，而非解析日期比较。使用能按字典序正确排序的格式——推荐 ISO 8601：

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

HTTP 标准格式 `Sat, 21 May 2026 12:00:00 GMT` 排序不正确——不要与此辅助工具一起使用。

### 304 无响应体

RFC 9110 禁止 304 响应有响应体。`ConditionalGetHelper` 返回空的 `createResponse(304)`，只要直接返回辅助工具的响应，这就能被正确处理。

## 条件写入——If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // 必须在写入前调用——写入后检查毫无意义。
    // If-Match 缺失时返回 428；If-Match 存在但错误时返回 412。
    // 前置条件通过时返回 null。
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * 通配符

客户端可以发送 `If-Match: *` 表示"只要资源存在就继续"。`ConditionalWriteHelper` 无条件通过此请求。**调用者负责在资源不存在时返回 404**——先获取记录，用 404 保护。

### 使 If-Match 可选

默认情况（`$require = true`），缺少 `If-Match` 返回 428。要允许不带前置条件头的写入：

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

仅在乐观锁对该资源确实是可选的情况下才放松此限制。

## 客户端流程

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304（无响应体）
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Updated" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       （已过期——内容已改变，ETag 现为 "def456"）

PATCH /articles/1         → 428 Precondition Required
  （无 If-Match 头）

PATCH /articles/1         → 200 { ... }
  If-Match: *              （通配符——任何现有版本）
```

## 每个响应都包含 ETag

在 POST、GET 和 PATCH 响应中都返回 `ETag`（以及 `Last-Modified`），这样客户端无需额外的往返请求就能获得最新值：

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag 与版本字段

| | ETag（HTTP 头） | 版本字段（响应体） |
|---|---|---|
| 检查位置 | HTTP 头 | 请求体 |
| 粒度 | 内容哈希 | 整数计数器 |
| 客户端需追踪 | ETag 值 | 版本号 |
| 最适合 | HTTP 缓存 + 乐观锁 | API 层冲突检测 |

两者可以结合使用：ETag 用于 HTTP 缓存，版本字段用于 DB 层冲突检测（见 [optimistic-locking.md](optimistic-locking.md)）。

## 代码审查清单

- [ ] ETag 字符串包含周围的双引号（`'"' . md5(...) . '"'`）
- [ ] ETag 生成集中在一处（实体方法），不在各处理器中重复
- [ ] 在构建 200 响应之前调用 `ConditionalGetHelper::check()`
- [ ] 同一 `$etag` 值传递给 `check()` 和 `withHeader('ETag', $etag)`
- [ ] 在写入之前调用 `ConditionalWriteHelper::check()`
- [ ] 304 响应体为空（直接使用辅助工具的响应）
- [ ] `Last-Modified` 值使用 ISO 8601 格式（需要字典序排序）
- [ ] 每个响应（201、200）都包含 `ETag`，确保客户端始终有最新值
- [ ] 测试覆盖：无 `If-None-Match` 的 200，匹配时的 304，过期 ETag 时的 200，无 `If-Match` 的 428，过期 `If-Match` 的 412，正确 `If-Match` 的 200，`If-Match: *`
