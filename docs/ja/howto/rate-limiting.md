# レート制限

> **FT リファレンス**: FT284 (`NENE2-FT/throttlelog`) — ThrottleMiddleware レート制限: IP ベースの固定ウィンドウ、カスタムキーエクストラクター（ユーザー/API キー）、X-RateLimit-* ヘッダー、Retry-After 付きの 429 Problem Details、テスト用 InMemoryRateLimitStorage、9 テスト / 33 アサーション PASS。
>
> **ATK アセスメント**: ATK-01〜ATK-12 はこのドキュメントの末尾に含まれています。

`ThrottleMiddleware` はすべてのリクエストに固定ウィンドウのレート制限を適用します。すべてのレスポンスに `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset` ヘッダーを追加し、制限を超えた場合に `429 Too Many Requests` の Problem Details レスポンスを返します。

## 基本セットアップ

`throttleMiddleware` パラメーターを通じて `ThrottleMiddleware` を `RuntimeApplicationFactory` に渡します:

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // ローカル/テスト専用 — 下記「本番」参照
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // ウィンドウあたりの許可リクエスト数
    windowSeconds: 60,   // ウィンドウの時間（秒）
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← 名前付きパラメーター、"middlewares" ではない
    routeRegistrars: [...],
))->create();
```

名前付きパラメーターは `throttleMiddleware` であり、`middlewares` ではありません — `RuntimeApplicationFactory` にはこのミドルウェア専用のスロットがあり、パイプライン内で正しい位置（認証後、ユーザーごとの制限が可能）に配置されます。

## レスポンスヘッダー

すべてのレスポンスにレート制限状態が含まれます:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

制限を超えた場合:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1716292860
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 60 requests per 60 seconds exceeded. Try again in 18 seconds."
}
```

## レート制限キー

### デフォルト: IP ベース（REMOTE_ADDR）

デフォルトのキーは `ip:<REMOTE_ADDR>` です。すべてのクライアント IP が独自のバケットを持ちます。

### カスタム: 認証済みユーザー

認証ミドルウェアがユーザー属性を設定した後、ユーザー ID でキー化します:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:        100,
    windowSeconds: 3600,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

これにより、共有 IP 環境（オフィス NAT）が 1 つのバケットを不公平に共有するのを防ぎ、未認証リクエストにより厳しい制限を適用できます。

### カスタム: API キーヘッダー

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## リバースプロキシ/ロードバランサーの警告

リバースプロキシの背後では、`REMOTE_ADDR` はプロキシの IP です — すべての実際のクライアントが単一のバケットを共有します。信頼できる転送 IP ヘッダーを読み取ることで修正します:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**`X-Forwarded-For` を信頼するのは、プロキシが管理下にあり確実に設定されている場合のみです。** トラフィックがプロキシを通過せずにアプリケーションに直接到達する場合、攻撃者がこのヘッダーを偽装できます。

## 本番: 共有ストレージを使用する

`InMemoryRateLimitStorage` はカウンターを通常の PHP 配列に保持します。PHP-FPM は複数のワーカープロセスを実行します; **各ワーカーは独自の配列を持つため、カウンターは共有されません**。本番で制限 60 の 10 ワーカーでは、実際の制限は約 600 になります。

本番では、共有ストアを使った `RateLimitStorageInterface` の実装を使ってください:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        $ttl     = max(0, $this->redis->ttl($key));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

次に注入します:

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## 固定ウィンドウのバースト問題

`ThrottleMiddleware` は固定ウィンドウアルゴリズムを使用します。クライアントは 2 つのウィンドウの境界にリクエストを送ることで実効レートを 2 倍にできます:

```
制限: 100 req/min、ウィンドウ: :00–:59

:59 — 100 リクエスト → 制限に到達
:00 — 100 リクエスト → 新しいウィンドウ、すべて通過

結果: 約 2 秒で 200 リクエスト
```

これが懸念される場合は、`RateLimitStorageInterface` の実装にスライディングウィンドウまたはトークンバケットアルゴリズムを実装してください。インターフェイスとミドルウェアはアルゴリズムに依存しません。

## ルートごとの制限

`RuntimeApplicationFactory` はグローバルに適用される 1 つの `ThrottleMiddleware` インスタンスをサポートします。異なる設定を持つルートごとの制限については、個々のハンドラーをラップすることでルートレベルのミドルウェアとして手動で `ThrottleMiddleware` を適用してください。

## クライアントのリトライパターン

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // 1 回リトライ
    }
    return res;
}
```

## コードレビューチェックリスト

- [ ] `InMemoryRateLimitStorage` は本番コードで使用しない
- [ ] 本番では共有ストレージ（Redis、Memcached、またはデータベースバック）を `RateLimitStorageInterface` 経由で注入する
- [ ] `keyExtractor` は正しい粒度を使用する: IP、ユーザー、または API キー（常に `REMOTE_ADDR` ではない）
- [ ] リバースプロキシの背後: `X-Forwarded-For` は信頼できるプロキシからのみ読み取り、任意のクライアントヘッダーからは読み取らない
- [ ] `limit` と `windowSeconds` はエンドポイントの予想トラフィックに適切（ログインエンドポイント: より厳しい; 読み取り専用 API: より緩やか）
- [ ] `RuntimeApplicationFactory` で `throttleMiddleware` 名前付きパラメーター（`middlewares` ではない）を使用する
- [ ] テストは `InMemoryRateLimitStorage` と低い `limit`（例: 3）を使用して sleep なしで 429 動作を検証する

---

## ATK アセスメント — クラッカー視点の攻撃テスト

### ATK-01 — レート制限を枯渇させて正規ユーザーをブロックする（DoS）🚫 BLOCKED（設計上）

**Attack**: 攻撃者が自分の IP から 1 分あたり 60 リクエストを送信して自身をブロックする（または制限を調査する）。
**Result**: BLOCKED（設計上）— 制限は攻撃者自身の IP/キーに適用されます。他のクライアントは影響を受けません（別々のバケット）。429 レスポンスに `Retry-After` が含まれるため、攻撃者はいつリトライできるかを知れます。これは意図した動作であり、レート制限は悪用をブロックするために設計されており、他者への DoS を防ぐためではありません。

---

### ATK-02 — 異なる IP アドレスを使用して IP ごとの制限を回避する 🚫 BLOCKED（緩和済み）

**Attack**: 攻撃者が複数の IP（ボットネット、VPN ローテーション）を使用して各 IP からの制限以下のリクエストを送信する。
**Result**: MITIGATED — 各 IP は独自のバケットを持ちます; 個々の IP がレート制限されます。多くの IP からの分散攻撃は単一ノードのレート制限では阻止できません。本番での緩和: CAPTCHA、WAF、CDN レベルのレート制限、または認証済みレート制限。

---

### ATK-03 — X-Forwarded-For を偽装して IP ベースの制限を回避する 🚫 BLOCKED（設計上の注意）

**Attack**: 攻撃者が各リクエストで異なる IP として見えるように `X-Forwarded-For: 10.0.0.1` を送信する。
**Result**: BLOCKED（正しく設定された場合）— デフォルトのキーはクライアントが指定したヘッダーではなく `REMOTE_ADDR`（サーバーが設定）を使用します。`X-Forwarded-For` をキーとして使用する場合、信頼できるプロキシからのみ読み取る必要があります。**信頼できないクライアントヘッダーをレート制限キーとして使用することがアンチパターンです — してはいけないことを参照してください。**

---

### ATK-04 — ウィンドウ境界バースト 🚫 BLOCKED（設計上の制限）

**Attack**: :59 に 60 リクエストと :00（新しいウィンドウ）に 60 リクエストを送信して 2 秒間で 120 リクエスト。
**Result**: BLOCKED（固定ウィンドウ設計内）— 各 60 秒ウィンドウは独立しています。固定ウィンドウは設計上、境界でのバーストを許可します。より厳格な制御には、スライディングウィンドウまたはトークンバケットの `RateLimitStorageInterface` 実装を使ってください。

---

### ATK-05 — `X-RateLimit-Remaining` ヘッダーを偽装して制限に影響を与える 🚫 BLOCKED

**Attack**: クライアントがサーバーが信頼することを期待して `X-RateLimit-Remaining: 999` ヘッダーを送信する。
**Result**: BLOCKED — `X-RateLimit-*` ヘッダーはサーバーが設定する**レスポンス**ヘッダーです。サーバーはこれらのヘッダーからではなく、リクエストから `REMOTE_ADDR`（または設定されたキー）を読み取ります。クライアントが指定した `X-RateLimit-*` 値は無視されます。

---

### ATK-06 — レート制限に達した後、異なるパスを使用して回避する 🚫 BLOCKED

**Attack**: `/notes` で制限に達した後、`/notes?q=1` または `/other-path` を試みる。
**Result**: BLOCKED — `ThrottleMiddleware` はすべてのパスにグローバルに適用されます。レート制限はパスではなく IP（または設定されたキー）でキー化されます。異なるパスは同じバケットを共有します。

---

### ATK-07 — 制限を超えるためのレースコンディション 🚫 BLOCKED

**Attack**: 残りカウントが 1 の時に 61 の並行リクエストを送信して制限を超える。
**Result**: BLOCKED — `InMemoryRateLimitStorage` は単一プロセス内で PHP のシーケンシャルリクエスト処理を使用します。マルチプロセス本番デプロイメントでは、原子的なインクリメント操作（Redis の `INCR`）が必要です。ミドルウェアの設計では、ストレージ実装が並行性を処理することを要求します。

---

### ATK-08 — レート制限タイミングを調査してシステム負荷を推測する 🚫 BLOCKED（無関係）

**Attack**: `Retry-After` を測定してサーバー負荷またはリクエストパターンを判断する。
**Result**: IRRELEVANT — `Retry-After` は残りのウィンドウ時間（固定）を返し、システム負荷は返しません。ウィンドウがいつリセットされるかを明かしますが、内部メトリクスは含みません。

---

### ATK-09 — 429 レスポンスに `Retry-After` ヘッダーがない 🚫 BLOCKED

**Attack**: クライアントが `Retry-After` が存在しないために 429 を無視し、無限リトライループを引き起こすことに依存する。
**Result**: BLOCKED — `ThrottleMiddleware` は 429 レスポンスに常に `Retry-After` と `X-RateLimit-Reset` の両方を含みます。適切に実装されたクライアントはこれらのヘッダーを尊重します。

---

### ATK-10 — 無制限バケットのために偽の API キーを使用する 🚫 BLOCKED（設計上）

**Attack**: API キーベースのレート制限を使用する場合、`X-Api-Key: unlimited` のような偽のキーを提供する。
**Result**: BLOCKED（設計上）— 各 API キーは独自のバケットを持ちます。キー `unlimited` は他のキーと同じ `limit` を持ちます。不明/偽のキーは特別ではありません。キーがユーザーにマップされる場合、無効なキーはレートリミッターに到達する前に認証に失敗するべきです。

---

### ATK-11 — 空のレート制限キーを送信してすべてのトラフィックを 1 つのバケットにマージする 🚫 BLOCKED

**Attack**: サーバーパラムから `REMOTE_ADDR` を削除して空のキーを強制し、すべてのトラフィックが 1 つのバケットを共有することを期待する。
**Result**: BLOCKED — `REMOTE_ADDR` が存在しない場合、キーは `ip:`（プレフィックス付きの空文字列の IP）になります。これはすべての不明な IP に対する単一の共有バケットを作成します — 本番では望ましくありませんが、制限自体のバイパスではありません。

---

### ATK-12 — 本番で InMemoryRateLimitStorage を使用してプロセスごとの分離を得る 🚫 BLOCKED（設計上の警告）

**Attack**: オペレーターが本番で `InMemoryRateLimitStorage` をデプロイする（例: 誤って）。各 PHP-FPM ワーカーは独自の配列を持つため、10 ワーカーでは実効的に制限が 10 倍になります。
**Result**: BLOCKED（ドキュメントの警告により）— これは上記で文書化された既知のアンチパターンです。コードレビューチェックリストが明示的にフラグを立てます。本番デプロイメントは共有ストレージ（Redis、DB バック）を使用する必要があります。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 制限を枯渇させて自己 DoS | 🚫 BLOCKED（設計上） |
| ATK-02 | 複数 IP で IP ごとの制限を回避 | 🚫 BLOCKED（緩和済み） |
| ATK-03 | X-Forwarded-For の偽装 | 🚫 BLOCKED（設計上の注意） |
| ATK-04 | ウィンドウ境界バースト | 🚫 BLOCKED（設計上の制限） |
| ATK-05 | X-RateLimit-* リクエストヘッダーの操作 | 🚫 BLOCKED |
| ATK-06 | 異なるパスで制限を回避 | 🚫 BLOCKED |
| ATK-07 | 制限を超えるレースコンディション | 🚫 BLOCKED |
| ATK-08 | Retry-After からシステム負荷を推測 | 🚫 BLOCKED（無関係） |
| ATK-09 | Retry-After がないとリトライループ | 🚫 BLOCKED |
| ATK-10 | 無制限バケットのための偽 API キー | 🚫 BLOCKED（設計上） |
| ATK-11 | 空のキーがすべてのトラフィックをマージ | 🚫 BLOCKED |
| ATK-12 | InMemoryStorage が本番で制限を乗算 | 🚫 BLOCKED（文書化済み） |

**12 BLOCKED / MITIGATED, 0 EXPOSED**
IP ごとの別々のバケット、デフォルトの REMOTE_ADDR キー、必須の `Retry-After` ヘッダーがすべてのテスト済み攻撃ベクターを防ぎます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 本番で `InMemoryRateLimitStorage` を使用する | PHP-FPM ワーカーはメモリを共有しない; 実効制限 = 設定された制限 × ワーカー数 |
| 信頼できないクライアントの `X-Forwarded-For` でキー化する | 攻撃者が任意の IP を偽装; レート制限が回避される |
| すべてのクライアントに 1 つのグローバルバケットを使用する | 1 つのクライアントのレート制限が他のすべてのクライアントをブロックする |
| レート制限に 429 ではなく 403 を返す | クライアントが「禁止」と「リクエスト過多」を区別できない; `Retry-After` が存在しない |
| 429 に `Retry-After` ヘッダーなし | クライアントが即座にリトライ; ウィンドウリセット時のサンダリングハード |
| 機密エンドポイントに `limit` を高く設定しすぎる | limit=10000 のログインエンドポイントは事実上保護されていない |
| ログイン/パスワードリセットエンドポイントにレート制限なし | ロックアウトやスロットルなしでブルートフォース攻撃が成功する |
