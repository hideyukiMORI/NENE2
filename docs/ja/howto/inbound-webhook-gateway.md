# ハウツー: インバウンド Webhook ゲートウェイ

> **FT リファレンス**: FT317 (`NENE2-FT/inboundlog`) — ソースごとの HMAC-SHA256 署名検証、重複 event_id 冪等性、レスポンスにシークレットを公開しない、インバウンド Webhook ゲートウェイ、17 テスト / 18 アサーション PASS。

このガイドでは、処理前にリクエストの正当性を検証するマルチソースインバウンド Webhook レシーバーの構築方法を示します。

## スキーマ

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- HMAC 共有シークレット
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- プロバイダー提供の重複排除キー
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- 生の JSON ボディ
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/sources` | 新しい Webhook ソースを登録する |
| `POST` | `/sources/{id}/receive` | Webhook イベントを受信する |
| `GET`  | `/sources/{id}/events` | ソースのイベントを一覧表示する |
| `GET`  | `/events/{id}` | 単一イベントを取得する |

## ソース登録

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// secret は絶対に返さない
```

```php
POST /sources  {"secret": "abc"}   → 422  // name が必須
POST /sources  {"name": "github"}  → 422  // secret が必須
```

## HMAC-SHA256 署名検証

各インバウンド Webhook は生ボディの HMAC-SHA256 を含む `X-Webhook-Signature` ヘッダーを含む必要があります:

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // 定時間比較
}
```

**重要**: タイミング攻撃を防ぐため `===` ではなく `hash_equals()` を使用してください。

## イベント受信

```php
// 送信者（例: Stripe）が計算:
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### エラーケース

```php
// 誤った署名または署名なし
POST /sources/1/receive  (不正な署名)  → 401 Unauthorized

// ソースが見つからない
POST /sources/9999/receive             → 404 Not Found

// ペイロードに event_id がない
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## 重複イベントの冪等性

プロバイダーの再試行は一般的です — `event_id` 重複排除が二重処理を防止します:

```php
// 最初の配信
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// 再試行（同じ event_id）
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

DB の `UNIQUE(source_id, event_id)` がストレージ層でこれを強制します。

## イベントのクエリ

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| ソースレスポンスで `secret` を返す | API レスポンスを読めるすべてのクライアントに署名キーが漏洩する |
| 署名に `hash_equals()` ではなく `===` を使う | タイミング攻撃が HMAC をバイトごとに明かす |
| `event_id` 重複排除なし | プロバイダーの再試行が二重処理を引き起こす（二重請求、重複メール） |
| JSON を解析した後に署名を検証する | 攻撃者が JSON パースを通過するが HMAC に失敗するボディを作れる。常に先に生バイトを検証すること |
| すべてのソースに単一のグローバルシークレット | 1 つの統合が危害を受けると全体が露出する |
