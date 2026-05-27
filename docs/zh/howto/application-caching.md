# 应用缓存实现指南

## 概述

本指南介绍如何使用 NENE2 实现应用缓存。涵盖 Cache-Aside（旁路缓存）模式、基于 TTL 的过期、写入时失效，以及将统计信息作为 REST API 提供。

---

## 端点设计

| 方法 | 路径 | 描述 |
|---|---|---|
| GET | `/products` | 商品列表（有缓存） |
| GET | `/products/{id}` | 商品详情（有缓存） |
| POST | `/products` | 创建商品（使列表缓存失效） |
| PUT | `/products/{id}` | 更新商品（使单条+列表缓存失效） |
| DELETE | `/products/{id}` | 删除商品（使单条+列表缓存失效） |
| POST | `/cache/clear` | 清除所有缓存 |
| GET | `/cache/stats` | 命中数、未命中数、条目数 |

---

## 缓存接口

```php
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 60): void;
    public function delete(string $key): void;
    public function flush(): void;
    /** @return array{hits: int, misses: int, size: int} */
    public function stats(): array;
}
```

---

## InMemoryCache（带 TTL 的缓存）

```php
class InMemoryCache implements CacheInterface
{
    private array $store  = [];
    private array $expiry = [];
    private int $hits     = 0;
    private int $misses   = 0;

    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null, // 测试用时钟注入
    ) {}

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            $this->misses++;
            return null;
        }
        if ($this->expiry[$key] <= $this->now()) {
            unset($this->store[$key], $this->expiry[$key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $this->store[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $this->store[$key]  = $value;
        $this->expiry[$key] = $this->now() + $effectiveTtl;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
```

---

## 设计要点

### Cache-Aside 模式

读取时检查缓存，未命中则从数据库获取并保存到缓存：

```php
public function handleGet(int $id): ResponseInterface
{
    $key = "product:{$id}";

    $cached = $this->cache->get($key);
    if ($cached !== null) {
        return $this->json->create(array_merge($cached, ['cached' => true]));
    }

    $product = $this->repo->find($id);
    if ($product === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    $this->cache->set($key, $product);
    return $this->json->create(array_merge($product, ['cached' => false]));
}
```

### 写入时失效

创建/更新/删除后删除相关缓存，使下次读取时从数据库获取最新数据：

```php
// POST /products
$this->cache->delete('products:list');

// PUT /products/{id}
$this->cache->delete("product:{$id}");
$this->cache->delete('products:list');

// DELETE /products/{id}
$this->cache->delete("product:{$id}");
$this->cache->delete('products:list');
```

### 缓存键设计

| 模式 | 示例键 |
|---|---|
| 单一资源 | `product:42` |
| 集合 | `products:list` |
| 带过滤条件 | `products:category:3:page:2` |
| 用户范围 | `user:7:cart` |

集合缓存在**写入频繁时设置较短的 TTL**，或采用**写入时删除**的策略。

### TTL 设计指南

| 数据特性 | 建议 TTL |
|---|---|
| 静态主数据（如分类） | 5~60 分钟 |
| 库存、价格（中等更新频率） | 30~60 秒 |
| 与用户会话相关 | 根据会话有效期调整 |
| 需要实时性的数据 | 不缓存 |

### 测试：通过时钟注入模拟 TTL

不依赖实际的 `time()`，通过注入的时钟控制时间：

```php
$time  = time();
$clock = function () use (&$time): int { return $time; };
$app   = AppFactory::createSqlite($dbFile, $clock);

$this->req('GET', '/products/1'); // 预热缓存（TTL=60s）

$time += 61; // 推进时钟超过 TTL

$res = $this->req('GET', '/products/1');
$this->assertFalse($data['cached']); // 已过期 → 缓存未命中
```

PHP 的 `static fn()` 不支持引用捕获，因此使用 `function () use (&$time)`。

### 通过缓存统计实现可观测性

```php
GET /cache/stats
→ {"hits": 42, "misses": 8, "size": 5}
```

命中率 = hits / (hits + misses)。在生产环境中可导出到 Prometheus/StatsD，以持续测量缓存效果。

---

## 响应示例

### GET /products（第一次——缓存未命中）

```json
{
  "products": [{"id": 1, "name": "Widget", "price": 9.99, "stock": 10}],
  "cached": false
}
```

### GET /products（第二次——缓存命中）

```json
{
  "products": [...],
  "cached": true
}
```

### GET /cache/stats

```json
{
  "hits": 5,
  "misses": 2,
  "size": 3
}
```

---

## 参考实现

`../NENE2-FT/cachelog/` — FT161 Field Trial（20 个测试，涵盖 TTL 过期、写入失效、统计功能）
