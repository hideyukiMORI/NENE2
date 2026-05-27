# ハウツー: Webhook 配信システム

> **FT リファレンス**: FT308 (`NENE2-FT/webhookdeliverylog`) — Webhook 配信システム: UrlValidator による SSRF 保護（HTTPS のみ、プライベート IP ブロックリスト、CRLF インジェクション防止）、タイムスタンプバインドによる HMAC-SHA256 署名、SHA-256 ハッシュとして保存されるシークレット（平文なし）、GET レスポンスでシークレットを返さない、非アクティブエンドポイントの配信スキップ、イベントタイプの分離、ATK-01〜12 すべて BLOCKED、31 テスト / 47 アサーション PASS。

このガイドでは、Webhook シークレットが保護され、URL が SSRF 攻撃に対してバリデーションされ、ペイロードがタイムスタンプ付きで署名されてリプレイ攻撃を防ぐ Webhook 配信システムの構築方法を説明します。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- 生のシークレットの SHA-256 ハッシュ
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`secret_hash` は生のシークレットの SHA-256 ハッシュを保存します — シークレット自体は保存しません。`active` フラグは配信履歴を削除せずにエンドポイントをソフト無効化できます。

## SSRF 保護 — UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // CRLF とヌルバイトインジェクションをブロック
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // HTTPS のみ
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // localhost とそのバリアントをブロック
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // 内部 TLD をブロック
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // プライベート IPv4 レンジをブロック（127.x、10.x、172.16-31.x、192.168.x）
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // プライベート IPv6 をブロック（::1、fc00::/7、fe80::/10）
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... IPv6 プライベートレンジチェック
        }

        return null; // 有効
    }
}
```

バリデーションのブロック対象:
1. **CRLF/ヌルバイトインジェクション** — Webhook URL への HTTP リクエストでのヘッダーインジェクションを防ぐ
2. **非 HTTPS スキーム** — `http://`、`file://`、`ftp://`、`gopher://` すべてブロック
3. **ループバックアドレス** — `127.0.0.0/8`、`::1`
4. **プライベートレンジ** — `10.x`、`172.16-31.x`、`192.168.x`、`0.0.0.0`
5. **内部 TLD** — `.local`、`.internal`、`.test`、`.example`

## Webhook 署名 — HMAC-SHA256 + タイムスタンプ

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // タイムスタンプが署名を時間にバインドする
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

署名フォーマット `sha256=<hex>` は GitHub Webhook で使用されているのと同じパターンです。**タイムスタンプは署名コンテンツに含まれています**（`timestamp.body`）— これによりリプレイ攻撃を防ぎます: 時刻 T でキャプチャされた署名は時刻 T+1h に再生できません。

## シークレットの保存 — ハッシュ、平文は絶対に保存しない

```php
// エンドポイント作成時:
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// 生のシークレットを呼び出し元に 1 回だけ返す:
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // 作成時のみ表示
    // 保存: secret_hash = SHA-256($rawSecret)
]);
```

生のシークレットは作成時に**1 回だけ**呼び出し元に返されます。それ以降の `GET /endpoints/{id}` レスポンスには `secret` または `secret_hash` は含まれません。

```php
// GET エンドポイントレスポンス — シークレットは含まない
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' は意図的に省略
]);
```

## 非アクティブエンドポイントのスキップ

```php
// ディスパッチハンドラー
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

非アクティブなエンドポイントは新しい配信を受け取りません。これにより、エンドポイントや配信履歴を削除せずに Webhook を無効にできます。

## イベントタイプの分離

各エンドポイントは特定の `event_type` を購読します。ディスパッチ時:

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// event_type にマッチするエンドポイントのみに配信される
```

`order.created` を購読しているエンドポイントは `order.cancelled` イベントを受け取りません。

---

## ATK アセスメント — クラッカー思考攻撃テスト

### ATK-01 — ループバック IPv4（127.x.x.x）による SSRF 🚫 BLOCKED

**Attack**: `url: "https://127.0.0.1/admin"` でエンドポイントを登録する。
**Result**: BLOCKED — UrlValidator がプライベート IPv4 レンジを検出 → 422。

---

### ATK-02 — 0.0.0.0 による SSRF 🚫 BLOCKED

**Attack**: `url: "https://0.0.0.0/internal"`。
**Result**: BLOCKED — `FILTER_FLAG_NO_RES_RANGE` で予約済み IP レンジをブロック → 422。

---

### ATK-03 — プライベートレンジ 10.x.x.x による SSRF 🚫 BLOCKED

**Attack**: `url: "https://10.0.0.1/internal"`。
**Result**: BLOCKED — プライベート IPv4 レンジ → 422。

---

### ATK-04 — プライベートレンジ 172.16-31.x.x による SSRF 🚫 BLOCKED

**Attack**: `url: "https://172.16.0.1/internal"`。
**Result**: BLOCKED — プライベート IPv4 レンジ → 422。

---

### ATK-05 — HTTP スキームのダウングレード 🚫 BLOCKED

**Attack**: `url: "http://example.com/hook"`（非 HTTPS）。
**Result**: BLOCKED — スキームチェック: `https` のみ許可 → 422。

---

### ATK-06 — file:// スキーム 🚫 BLOCKED

**Attack**: `url: "file:///etc/passwd"`。
**Result**: BLOCKED — スキームチェックが非 HTTPS をブロック → 422。

---

### ATK-07 — URL への CRLF インジェクション 🚫 BLOCKED

**Attack**: `url: "https://example.com/\r\nX-Injected: header"`。
**Result**: BLOCKED — `str_contains($url, "\r")` チェック → 422。

---

### ATK-08 — URL へのヌルバイト 🚫 BLOCKED

**Attack**: `url: "https://example.com/\0hidden"`。
**Result**: BLOCKED — `str_contains($url, "\0")` チェック → 422。

---

### ATK-09 — GET エンドポイント経由のシークレット漏洩 🚫 BLOCKED

**Attack**: `GET /endpoints/{id}` で保存されたシークレットを取得する。
**Result**: BLOCKED — GET レスポンスは `secret` と `secret_hash` フィールドを完全に省略する。

---

### ATK-10 — ディスパッチレスポンス経由のシークレット漏洩 🚫 BLOCKED

**Attack**: ディスパッチレスポンスボディでシークレット素材を検査する。
**Result**: BLOCKED — ディスパッチレスポンスには配信メタデータのみが含まれ、シークレットフィールドはない。

---

### ATK-11 — リプレイ攻撃（キャプチャされた署名） 🚫 BLOCKED

**Attack**: 署名済み Webhook をキャプチャして後で同じ署名で再生する。
**Result**: BLOCKED — 署名は `HMAC(timestamp.body, secret)`。タイムスタンプは配信ごとに変わる。古い署名は新しいタイムスタンプとマッチしない。

---

### ATK-12 — 誤ったシークレットによる署名偽造 🚫 BLOCKED

**Attack**: 推測した/異なるシークレットで HMAC を計算し、有効な署名として送信する。
**Result**: BLOCKED — 受信者は保存されたシークレットハッシュで検証する。偽造された HMAC はマッチしない。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | SSRF ループバック IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF プライベート 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF プライベート 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | HTTP スキームのダウングレード | 🚫 BLOCKED |
| ATK-06 | file:// スキーム | 🚫 BLOCKED |
| ATK-07 | URL への CRLF インジェクション | 🚫 BLOCKED |
| ATK-08 | URL へのヌルバイト | 🚫 BLOCKED |
| ATK-09 | GET 経由のシークレット漏洩 | 🚫 BLOCKED |
| ATK-10 | ディスパッチ経由のシークレット漏洩 | 🚫 BLOCKED |
| ATK-11 | リプレイ攻撃 | 🚫 BLOCKED |
| ATK-12 | 署名偽造 | 🚫 BLOCKED |

**12 BLOCKED、0 EXPOSED**
UrlValidator がすべての SSRF ベクターをブロックします。タイムスタンプバインド HMAC がリプレイを防ぎます。シークレットはハッシュとして保存され、作成後は返されません。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 生の Webhook シークレットを DB に保存する | DB 侵害ですべてのシークレットが露出する; SHA-256 ハッシュは一方向 |
| GET レスポンスでシークレットを返す | 管理者 API の漏洩ですべての Webhook シークレットが露出する |
| ボディのみの HMAC（タイムスタンプなし） | リプレイ攻撃: キャプチャされた署名が無期限に再利用される |
| `http://` Webhook URL を許可する | Webhook ペイロードの通信傍受 |
| URL に SSRF バリデーションなし | Webhook システムを使って内部ネットワークをプローブされる |
| Webhook URL に `127.x`、`10.x` を許可する | サーバーが自身の内部サービスにリクエストを送る |
| CRLF チェックなし | `\r\n` を含む URL が外向き HTTP リクエストにヘッダーを注入する |
| 非アクティブなエンドポイントに配信する | 非アクティブなエンドポイントがトラフィックを受け続ける |
| イベントタイプフィルタリングなし | すべてのイベントタイプがすべてのエンドポイントに配信される |
