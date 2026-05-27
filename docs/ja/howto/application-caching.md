# Application Caching の実装ガイド

## 概要

このガイドでは NENE2 を使ってアプリケーションキャッシュを実装する方法を説明します。
Cache-Aside（ルックアサイド）パターン・TTL ベース期限・書き込み時無効化・統計を REST API として提供します。

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| GET | `/products` | 商品一覧（キャッシュあり） |
| GET | `/products/{id}` | 商品詳細（キャッシュあり） |
| POST | `/products` | 商品作成（一覧キャッシュを無効化） |
| PUT | `/products/{id}` | 商品更新（個別＋一覧を無効化） |
| DELETE | `/products/{id}` | 商品削除（個別＋一覧を無効化） |
| POST | `/cache/clear` | 全キャッシュクリア |
| GET | `/cache/stats` | ヒット数・ミス数・エントリ数 |

---

## キャッシュインターフェース

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

## InMemoryCache（TTL 付きキャッシュ）

```php
class InMemoryCache implements CacheInterface
{
    private array $store  = [];
    private array $expiry = [];
    private int $hits     = 0;
    private int $misses   = 0;

    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null, // テスト用クロック注入
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

## 設計のポイント

### Cache-Aside パターン

読み取り時にキャッシュを確認し、ミスなら DB から取得してキャッシュに保存する:

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

### 書き込み時無効化

create/update/delete 後は関連キャッシュを削除して次の読み取りで DB から新鮮なデータを取得させる:

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

### キャッシュキー設計

| パターン | キー例 |
|---|---|
| 単一リソース | `product:42` |
| コレクション | `products:list` |
| フィルター付き | `products:category:3:page:2` |
| ユーザースコープ | `user:7:cart` |

コレクションキャッシュは **書き込みが多い場合は TTL を短めに** 設定するか、**書き込み時に削除** する方針を選ぶ。

### TTL 設計指針

| データ性質 | 推奨 TTL |
|---|---|
| 静的マスター（カテゴリ等） | 5〜60 分 |
| 在庫・価格（更新頻度中程度） | 30〜60 秒 |
| ユーザーセッション系 | 必要に応じてセッション有効期限まで |
| リアルタイム性が必要なデータ | キャッシュしない |

### テスト: クロック注入で TTL をシミュレート

実際の `time()` に依存せず、インジェクトしたクロックで時刻を制御できる:

```php
$time  = time();
$clock = function () use (&$time): int { return $time; };
$app   = AppFactory::createSqlite($dbFile, $clock);

$this->req('GET', '/products/1'); // warm cache (TTL=60s)

$time += 61; // advance clock past TTL

$res = $this->req('GET', '/products/1');
$this->assertFalse($data['cached']); // expired → cache miss
```

PHP の `static fn()` は参照キャプチャに非対応なため `function () use (&$time)` を使う。

### キャッシュ統計でオブザーバビリティ

```php
GET /cache/stats
→ {"hits": 42, "misses": 8, "size": 5}
```

ヒット率 = hits / (hits + misses)。本番では Prometheus/StatsD にエクスポートすることで
キャッシュ効果を継続的に測定できる。

---

## レスポンス例

### GET /products（1回目 — キャッシュミス）

```json
{
  "products": [{"id": 1, "name": "Widget", "price": 9.99, "stock": 10}],
  "cached": false
}
```

### GET /products（2回目 — キャッシュヒット）

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

## 参照実装

`../NENE2-FT/cachelog/` — FT161 フィールドトライアル（20 テスト・TTL 期限・書き込み無効化・統計）
