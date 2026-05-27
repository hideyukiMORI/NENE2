# ハウツー: 固定ウィンドウ レートリミッター

> **FT リファレンス**: FT251 (`NENE2-FT/ratelimitlog`) — SQLite upsert を使った固定ウィンドウ レートリミッティング

SQLite に保存された固定ウィンドウ レートリミッターを実演します。各 `(key, window_start)` ペアがリクエストカウントを累積します。カウントが設定された制限を超えると、リクエストは `429 Too Many Requests` と `Retry-After` ヘッダーで拒否されます。

---

## ルート

| メソッド | パス | 説明 |
|--------|-----------|-------------------------------------------------|
| `GET`  | `/ping`   | レート制限エンドポイント（`X-Client-Key` を読み取る） |
| `GET`  | `/status` | キーの読み取り専用カウンター（`?key=`） |

---

## スキーマ: レートリミットカウンターストアとしての複合主キー

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- ウィンドウ境界に切り捨てられた ISO 8601 タイムスタンプ
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` は各（クライアント、ウィンドウ）ペアのカウンターを一意に識別します。インデックスは upsert 検索を高速にします。別の `api_calls` ログテーブルが監査目的で各成功リクエストを記録します。

---

## Upsert パターン: `INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

`(key, windowStart)` ペアの最初のリクエストが `count = 1` を挿入します。同じウィンドウ内の後続リクエストは `DO UPDATE SET count = count + 1` でアトミックにインクリメントします。upsert は SQLite でアトミックなため、`INSERT` の前に `SELECT` は不要です。

upsert 後、制限を超えたかどうかを検知するためにカウンターを読み取ります:

```php
$row   = $this->executor->fetchOne(
    'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
    [$key, $windowStart],
);
$count = (int) ($row['count'] ?? 0);

if ($count > $this->limit) {
    $retryAfter = (int) (strtotime($windowEnd) - strtotime($now));
    throw new RateLimitExceededException($key, $this->limit, $this->windowSeconds, max(0, $retryAfter));
}
```

チェックはインクリメント**後**に行われます。これは（limit+1）番目のリクエストが拒否される前にカウントされることを意味します — 制限超過リクエストのカウンターは `limit + 1` に達します。これは意図的なものです: カウントは許可されたものだけでなく、試行の総数を正確に反映します。

---

## ウィンドウの切り捨て: 固定境界

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` は現在のウィンドウ内のオフセットです。引き算するとウィンドウ開始タイムスタンプが得られます。`2026-01-01T00:00:45Z` での 60 秒ウィンドウの場合:

```
ts     = 1751328045  （Unix タイムスタンプ）
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

`:00` から `:59` までのすべてのリクエストが同じ `window_start = 2026-01-01T00:00:00Z` を共有します。`:60` で新しいウィンドウが開始し、カウンターがリセットされます。

**固定ウィンドウ vs スライディングウィンドウのトレードオフ**:

| プロパティ | 固定ウィンドウ | スライディングウィンドウ |
|---|---|---|
| 実装 | リクエストあたり 1 つの upsert | バケット間で複数の読み書き |
| メモリ | (key, window) あたり 1 行 | key あたり N 行（サブバケット） |
| 境界でのバースト | あり — ウィンドウエッジで 2× の制限が可能 | なし — 時間をまたいでスムーズに制限 |
| 一般的な用途 | シンプルな API、内部ツール | パブリック向け API、厳格な公平性 |

---

## `Retry-After` ヘッダー付き `429 Too Many Requests`

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,
    ) {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds}s exceeded for key '{$key}'.");
    }
}
```

例外は現在のウィンドウが終了するまでの秒数 `retryAfter` を持ちます。ハンドラーはこれを `Retry-After` ヘッダー付きの `429` Problem Details レスポンスにマップします:

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);

    $response = $this->probs->create(
        request: $request,
        type: 'rate-limit-exceeded',
        title: 'Too Many Requests',
        status: 429,
        detail: $exception->getMessage(),
    );

    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`Retry-After` はクライアントがリトライ前に待機すべき秒数です。`windowEnd - now` として計算され、`>= 0` にクランプされます。

---

## `X-Client-Key` ヘッダーによるクライアントごとのキー

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

各クライアントは `X-Client-Key` ヘッダーで識別されます。ヘッダーがない場合は `'127.0.0.1'` にフォールバックします — すべての未認証クライアントが 1 つのカウンターを共有します。本番環境では:

- クライアントが偽造できるヘッダーではなく、認証済みセッションから抽出した検証済みユーザー ID や API キーを使用してください。
- IP ベースの制限には（プロキシ剥ぎ後の）`$_SERVER['REMOTE_ADDR']` を使用してください。
- `X-Forwarded-For` を直接使用してはいけません — クライアントは制限をバイパスするために偽造できます。

---

## 読み取り専用ステータスエンドポイント

```php
public function currentCount(string $key, string $now): int
{
    $windowStart = $this->truncateToWindow($now);
    $row = $this->executor->fetchOne(
        'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
        [$key, $windowStart],
    );

    return (int) ($row['count'] ?? 0);
}
```

`GET /status?key=xxx` はインクリメントせずに現在のカウンターを返します。監視ダッシュボードやクライアントサイドのバックオフロジックに使用されます。

---

## ウィンドウの有効期限プルーニング

```php
public function pruneExpired(string $now): int
{
    $cutoff = $this->subtractSeconds($now, $this->windowSeconds * 2);

    return $this->executor->execute(
        'DELETE FROM rate_limit_windows WHERE window_start < ?',
        [$cutoff],
    );
}
```

古いウィンドウは時間とともに蓄積されます。`pruneExpired()` は 2 ウィンドウ期間前より古い行を削除します（現在のウィンドウと前のウィンドウは保持され、それより古いものは削除されます）。

バックグラウンドタスクから、または各リクエスト後に（サンプリング付きで — 例: リクエストの約 1% で実行するために `rand(0, 99) === 0`）`pruneExpired()` を実行してください:

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## 設定インジェクション

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` と `windowSeconds` は構築時に注入されます。異なるエンドポイントが異なる設定で異なるリミッターインスタンスを使用できます:

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## 関連ハウツー

- [`rate-limiting.md`](rate-limiting.md) — ルートごとのレートリミッティングのための `ThrottleMiddleware`
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — サブバケットを使ったスライディングウィンドウ（ratelog FT200）
- [`add-rate-limiting.md`](add-rate-limiting.md) — 既存ルートへのレートリミッティングの追加
- [`quota-management.md`](quota-management.md) — 長期間のクォータ（日次、月次）
- [`api-usage-metering.md`](api-usage-metering.md) — クォータチェック付きのユーザーごとの使用追跡
