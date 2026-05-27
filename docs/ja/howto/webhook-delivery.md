# アウトバウンド Webhook 配信

アウトバウンド Webhook は、アプリケーションでイベントが発生したときにサードパーティシステムに通知します。主なセキュリティの懸念事項は SSRF（内部インフラへのリクエスト送信）、シークレット漏洩、署名の整合性です。

## コアコンポーネント

- **エンドポイントレジストリ**: サブスクライバーごとに URL、イベントフィルター、ハッシュ化されたシークレットを保存する。
- **配信キュー**: (エンドポイント, イベント) ペアごとに 1 レコード、試行回数とステータスを追跡する。
- **署名者**: 受信者が検証できる HMAC-SHA256 署名を生成する。
- **URL バリデーター**: エンドポイントを保存する前に SSRF ターゲットをブロックする。

## スキーマ

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- 生のシークレットの SHA-256; 生のシークレットは保存しない
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL,
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending | delivered | failed
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,                             -- 最後の HTTP レスポンスコード
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

シークレットの SHA-256 ハッシュのみが保存されます。生のシークレットは永続化されません — DB が侵害されても、ハッシュを逆算して署名を偽造することはできません（ランダムな 32 バイトシークレットに対して HMAC なしの SHA-256 は可逆でない）。

## 署名フォーマット

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

署名コンテンツ: `{timestamp}.{body}` — 署名をペイロードと時点の両方にバインドします。

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

署名コンテンツにタイムスタンプを含めることでリプレイ攻撃を防ぎます: 有効な Webhook をキャプチャした攻撃者は後で再利用できません。タイムスタンプが古くなるためです。受信者はしきい値（例: 5 分）より古い署名を拒否すべきです。

## SSRF 防止

保存前にすべての Webhook URL をバリデーションしてください。最低限、以下をブロックしてください:

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // CRLF/ヌルバイトインジェクションをブロック
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // HTTPS のみ
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // プライベート/ループバック IP と予約済みホスト名をブロック
        // ...
    }
}
```

ブロックするプライベート IPv4 レンジ: `127.0.0.0/8`、`10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`、`169.254.0.0/16`、`0.0.0.0`。

ブロックするホスト名: `localhost`、`*.local`、`*.internal`、`*.test`、`*.invalid`。

IPv6: `::1`、`fc00::/7`（ULA）、`fe80::/10`（リンクローカル）。

**DNS リバインディング**: 登録時の URL バリデーションだけでは不十分です — DNS レコードが登録から配信の間に内部 IP を指すように変更される可能性があります。本番環境では、TCP 接続を開く前の配信時にも解決された IP をバリデーションしてください。

## レスポンスフィルタリング — シークレットを絶対に露出しない

`WebhookEndpoint` の `toArray()` メソッドは `secret` と `secret_hash` の両方を省略する必要があります:

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash は意図的に除外
    ];
}
```

これは GET /webhooks/{id}、エンドポイント一覧、エンドポイントメタデータを記録する監査ログに適用されます。

## リトライロジック

```php
public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
{
    $newCount  = $delivery->attemptCount + 1;
    $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

    $this->executor->execute(
        'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_error = ?, updated_at = ? WHERE id = ?',
        [$newStatus, $newCount, $error, $now, $id],
    );
}
```

- `attempt_count < max_retries` → ステータスが `pending` に留まる → ワーカーが再度ピックアップする。
- `attempt_count >= max_retries` → ステータスが `failed` になる → これ以上リトライなし。

ワーカーは苦しんでいる受信者を打ちつけるのを避けるために指数バックオフ（例: `2^attempt_count` 秒）を実装すべきです。

## 非アクティブ化

非アクティブなエンドポイント（`active = 0`）はディスパッチ時のファンアウトクエリから除外されます:

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

これにより、サブスクライバーは登録を削除せずに配信を一時停止できます。

## 設計上の決定

**なぜ生のシークレットではなく `secret_hash` を保存するのか?**
DB が侵害された場合、攻撃者はシークレットを抽出して受信者に送られる Webhook 署名を偽造できません。生のシークレットは作成時に 1 回だけ返され、呼び出し元が安全に保管する必要があります。

**なぜ署名にタイムスタンプを含めるのか?**
タイムスタンプのない署名は無期限に再生可能です。HMAC に `{timestamp}.{body}` を含めることで、Webhook を傍受した攻撃者は再送できません — 受信者は ±5 分の窓外のタイムスタンプを拒否できます。

**なぜディスパッチ時ではなく登録時に URL をバリデーションするのか?**
登録時に無効な URL をブロックすることでサブスクライバーに即座のフィードバックを提供し、不正なデータが配信キューに入るのを防ぎます。DNS リバインディング攻撃にはディスパッチ時の追加バリデーションが必要です。
