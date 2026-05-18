# レート制限を追加する

このガイドでは、`ThrottleMiddleware` と `RateLimitStorageInterface` を使って NENE2 アプリケーションにリクエストレート制限を追加する方法を説明します。

**前提条件**: 動作する NENE2 アプリケーションがあること。まだの場合は [チュートリアル](../tutorial/first-api.md) から始めてください。

---

## クイックスタート

`RuntimeApplicationFactory` に `ThrottleMiddleware` を追加します。組み込みの `InMemoryRateLimitStorage` はローカル開発とシングルプロセス環境に適しています。

```php
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17    = new Psr17Factory();
$problems = new ProblemDetailsResponseFactory($psr17, $psr17);
$storage  = new InMemoryRateLimitStorage();

$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          60,   // ウィンドウあたりの許可リクエスト数
    windowSeconds:  60,   // ウィンドウ長（秒）
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` はミドルウェアスタックのポジション 8（認証の後）に配置されます。認証済みユーザーごとに制限をかけることもできます（[カスタムキー抽出](#カスタムキー抽出) を参照）。

---

## 動作の仕組み

リクエストごとにミドルウェアは:

1. クライアントのキーを計算します（デフォルト: `REMOTE_ADDR`）。
2. ストレージバックエンドのカウンターをインクリメントします。
3. カウンターが制限**以下**の場合 — リクエストを通過させ、レート制限ヘッダーを追加します。
4. カウンターが制限を**超えた**場合 — `429 Too Many Requests` と Problem Details を返します。

### レスポンスヘッダー

すべてのレスポンス（429 を含む）に以下のヘッダーが付与されます:

| ヘッダー | 値 |
|---|---|
| `X-RateLimit-Limit` | ウィンドウあたりの設定制限数 |
| `X-RateLimit-Remaining` | 現在のウィンドウの残りリクエスト数 |
| `X-RateLimit-Reset` | ウィンドウがリセットされる Unix タイムスタンプ |
| `Retry-After` | ウィンドウリセットまでの秒数（429 のみ） |

### 429 レスポンスボディ

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit exceeded. Try again in 42 seconds.",
  "instance": "/examples/notes"
}
```

---

## カスタムキー抽出

デフォルトのキーはクライアント IP アドレス（`REMOTE_ADDR`）です。`Closure` を渡すことで、認証済みユーザー、API キー、その他の次元でキー制限をかけられます。

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          1000,
    windowSeconds:  3600,
    keyExtractor:   static function (ServerRequestInterface $request): string {
        // BearerTokenMiddleware がトークン検証後にセットした属性を使用
        return $request->getAttribute('user_id', 'anonymous');
    },
);
```

---

## ストレージバックエンドを交換する

`InMemoryRateLimitStorage` はカウンターを PHP プロセスメモリに保存します。FPM 環境ではリクエストごとにリセットされ、**プロセス間で共有されません**。本番環境では Redis などの共有ストアが必要です。

`RateLimitStorageInterface` を実装します:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    /** @return array{count: int, reset_at: int} */
    public function hit(string $key, int $windowSeconds): array
    {
        $redisKey = "rate:{$key}";
        $count    = (int) $this->redis->incr($redisKey);

        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }

        $ttl     = max(0, (int) $this->redis->ttl($redisKey));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

`InMemoryRateLimitStorage` の代わりに注入します:

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        new RedisRateLimitStorage($redis),
    limit:          200,
    windowSeconds:  60,
);
```

---

## 設計の判断について

固定ウィンドウアルゴリズムの選択、IP キーのデフォルト、ヘッダー規約、`RateLimitStorageInterface` 抽象境界の根拠は [ADR 0010](/adr/0010-rate-limiting) を参照してください。

---

## 次のステップ

完全な `429` エラー形状は [Problem Details タイプ](../reference/problem-details-types.md) を参照するか、
補完的な可観測性機能として [ヘルスチェックを追加する](./add-health-check.md) を参照してください。
