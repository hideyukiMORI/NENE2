# Payment Webhook 受信の実装ガイド

## 概要

このガイドでは NENE2 を使って Payment Webhook 受信 API を実装する方法を説明します。
HMAC-SHA256 署名検証・冪等処理（event_id UNIQUE 制約）・ステータス遷移ガードを提供します。

---

## DB スキーマ

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,               -- 最小通貨単位（円・セント）
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,   -- 冪等キー
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,          -- JSON
    processed_at TEXT    NOT NULL
);
```

`webhook_events.event_id` が**冪等処理**の核心。同じ event_id を 2 回受け取っても 1 回のみ処理する。

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/webhooks/payment` | Webhook イベント受信・処理 |
| GET | `/payments` | 決済一覧 |
| GET | `/payments/{id}` | 決済詳細 |

---

## ステータス遷移

```
[created] → pending → succeeded → refunded
                    ↘ failed
```

遷移テーブルで管理:

```php
private const array VALID_TRANSITIONS = [
    'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
    'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
    'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
];
```

不正な遷移（failed → succeeded 等）は 409 Conflict を返す。

---

## 設計のポイント

### HMAC-SHA256 署名検証

リクエストボディ全体を HMAC-SHA256 で検証する。Stripe 互換の `X-Webhook-Signature: sha256=<hex>` ヘッダーを使用:

```php
private function verifySignature(string $body, string $header): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $body, $this->webhookSecret);
    return hash_equals($expected, $provided); // タイミング攻撃防止
}
```

`hash_equals()` で定時間比較する。`===` や `strcmp()` は早期終了するため脆弱。

### 冪等処理

Webhook プロバイダーはリトライする。`event_id` で重複を排除:

```php
// 処理前に確認
if ($this->repo->isEventProcessed($eventId)) {
    return $this->json->create(['status' => 'already_processed']);
}

// 処理後に記録
$this->repo->recordEvent($eventId, $eventType, $payload, $now);
```

### 処理順序

```
1. 署名検証 → 401
2. event_id の重複確認 → 200 already_processed
3. イベントタイプ別処理
4. webhook_events に記録
5. 200 processed を返す
```

**署名検証を最初に行う**ことで、攻撃者が event_id テーブルを汚染できないようにする。

### 不明なイベントタイプは 200 で返す

プロバイダーが新しいイベントタイプを追加したとき、4xx を返すとリトライが発生する。
不明なタイプは静かに 200 で返して記録する:

```php
// Unknown event type — acknowledge without processing
return null; // → 200 processed
```

### テスト: 署名をインジェクトした SECRET で生成

```php
private const string SECRET = 'test-webhook-secret';

private function signedReq(string $path, array $body): ResponseInterface
{
    $rawBody = json_encode($body);
    $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    // ...
}
```

`AppFactory::createSqlite($dbFile, self::SECRET)` で同じシークレットをアプリに渡す。

---

## イベントペイロード例

### payment.created

```json
{
  "event_id": "evt_001",
  "event_type": "payment.created",
  "data": {"id": "pay_abc", "amount": 5000, "currency": "jpy"}
}
```

### payment.succeeded

```json
{
  "event_id": "evt_002",
  "event_type": "payment.succeeded",
  "data": {"id": "pay_abc"}
}
```

### レスポンス（成功）

```json
{"status": "processed", "event_type": "payment.succeeded"}
```

### レスポンス（冪等再送）

```json
{"status": "already_processed"}
```

---

## 参照実装

`../NENE2-FT/paymentlog/` — FT163 フィールドトライアル（18 テスト・署名検証・冪等処理・遷移ガード）
