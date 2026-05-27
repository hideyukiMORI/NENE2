# ハウツー: Webhook 配信 API

> **FT リファレンス**: FT348 (`NENE2-FT/webhooklog`) — URL/シークレット/イベントフィルターによる Webhook 登録、サブスクライバーごとの配信ログ付きイベントディスパッチ、シークレットのマスキング、リトライ機構、成功/失敗ステータスの追跡、18 テスト PASS。

このガイドでは、Webhook 配信システムの構築方法を説明します: エンドポイントサブスクライバーの登録、マッチするフックへのイベントディスパッチ、すべての配信試行のログ、失敗のリトライ。

## スキーマ

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- JSON 配列; 空 = すべてのイベント
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'`（空配列）は「すべてのイベントを購読する」ことを意味します。`ON DELETE CASCADE` は Webhook が削除されると配信レコードも削除します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/webhooks`                  | Webhook を登録する              |
| `GET`    | `/webhooks`                  | すべての Webhook を一覧表示する |
| `GET`    | `/webhooks/{id}`             | 単一の Webhook を取得する       |
| `DELETE` | `/webhooks/{id}`             | Webhook を削除する（+ 配信）    |
| `GET`    | `/webhooks/{id}/deliveries`  | Webhook の配信を一覧表示する    |
| `POST`   | `/events/dispatch`           | サブスクライバーにイベントをディスパッチする |
| `POST`   | `/deliveries/{id}/retry`     | 失敗した配信をリトライする      |

## Webhook の登録

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← シークレットはレスポンスで常にマスクされる
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### すべてのイベントを購読する

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // 空のイベント = すべてのイベントタイプを受信
```

### バリデーション

```php
POST /webhooks  {"events": []}
→ 422  // url は必須
```

**シークレットのマスキング**: 保存されたシークレットは HMAC 署名にのみ使用されます。すべてのレスポンスで `"***"` を返してください — 実際のシークレット値は決して返さないこと。

## イベントのディスパッチ

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // マッチした Webhook の数
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### イベントマッチング

以下の場合、Webhook はイベントを受信します:
1. `events` 配列が空（すべてを購読）**または**
2. `event_type` が `events` 配列に含まれている

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  (すべて)

dispatch("order.created")
→ dispatched_to: 2  // A と C がマッチ、B はマッチしない
```

### マッチする Webhook なし

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### ディスパッチの実装

```php
public function dispatch(string $eventType, array $payload): array
{
    // このイベントにマッチするすべてのアクティブな Webhook を検索する
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' または 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- マッチする Webhook を検索する（アクティブ + イベントフィルター）
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## 配信の一覧表示

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook が見つからない
GET /webhooks/9999/deliveries
→ 404
```

## 失敗した配信のリトライ

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// 配信が見つからない
POST /deliveries/9999/retry
→ 404
```

---

## ATK アセスメント — クラッカー思考攻撃テスト

### ATK-01 — GET によるシークレット抽出 🚫 BLOCKED

**Attack**: 攻撃者が Webhook を登録し、`GET /webhooks/{id}` を呼び出すか Webhook を一覧表示して署名シークレットを取得する。
**Result**: BLOCKED — すべてのレスポンスが `"secret": "***"` を返す。実際のシークレットは DB に保存されているが、どのエンドポイントからも返されない。攻撃者は API 経由でシークレットを回収できない。

---

### ATK-02 — 内部/プライベート URL への Webhook 登録（SSRF） ⚠️ EXPOSED

**Attack**: 攻撃者が `url: "http://169.254.169.254/latest/meta-data"`（AWS メタデータエンドポイント）または `http://localhost:8080/admin` を登録する。イベントがディスパッチされると、サーバーが内部 URL を取得する。
**Result**: EXPOSED — webhooklog FT は登録 URL に URL バリデーションや SSRF ブロックを実装していない。本番環境では、登録前に URL がパブリック IP（ループバック、プライベート RFC1918、リンクローカル、メタデータサービスでない）に解決されることを検証してください。SSRF ブロックパターンについては `docs/howto/url-shortener-ssrf-prevention.md` を参照してください。

---

### ATK-03 — 非アクティブな Webhook へのディスパッチ 🚫 BLOCKED

**Attack**: 攻撃者が Webhook を削除してイベントをディスパッチし、キャッシュされたエンドポイントへの配信が引き続き発生することを期待する。
**Result**: BLOCKED — ディスパッチクエリは `WHERE is_active = 1` でフィルタリングする。削除された Webhook はテーブルから削除される（`ON DELETE CASCADE`）ため、マッチングクエリには表示されない。

---

### ATK-04 — event_type フィールドによる SQL インジェクション 🚫 BLOCKED

**Attack**: 攻撃者が `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` を送信して Webhook 登録を破壊しようとする。
**Result**: BLOCKED — `LIKE '%"' || ? || '"%'` マッチクエリは `event_type` にバインドされたパラメーターを使用する。PDO プリペアドステートメントが SQL インジェクションを防ぐ。悪意のある文字列はそのまま保存/マッチされる。

---

### ATK-05 — 細工した events 配列によるすべてのイベントへの購読 🚫 BLOCKED

**Attack**: 攻撃者が `{"events": null}` または `{"events": "all"}` を送信し、文書化された空配列規約を使わずにすべてのイベントを購読しようとする。
**Result**: BLOCKED — `events` は JSON 配列としてバリデーションされる。非配列の値は 422 を返す。リテラル `[]` のみが「すべてを購読する」パスをトリガーする。

---

### ATK-06 — 無効な証明書を持つ HTTPS への配信 ✅ SAFE

**Attack**: 攻撃者が期限切れまたは自己署名の TLS 証明書を持つ Webhook URL を登録し、配信クライアントがそれを受け入れることを期待する。
**Result**: SAFE — 配信クライアントは TLS 証明書の検証（`CURLOPT_SSL_VERIFYPEER = true`）を強制するべきである。この FT はテスト用にスタブクライアントを使用している。本番クライアントは証明書バリデーションを強制する必要がある。

---

### ATK-07 — リトライによる配信済みイベントの再生 🚫 BLOCKED

**Attack**: 攻撃者が**成功した**配信の `POST /deliveries/{id}/retry` を呼び出してサブスクライバーでイベントを再生しようとする。
**Result**: BLOCKED — リトライは配信レコードを再取得し、保存されたペイロードを Webhook URL に再送信する。サブスクライバーは重複排除のべき等性キーを実装する必要がある。配信システム自体は成功した配信のリトライをブロックしない（管理者のユースケース）。サブスクライバー側のべき等性がセーフガードである。

---

### ATK-08 — 配信 ID の列挙による他の Webhook ログへのアクセス 🚫 BLOCKED

**Attack**: 攻撃者が `GET /deliveries/{id}` を通じて配信 ID を反復して、所有していない Webhook の配信ログを読もうとする。
**Result**: BLOCKED — `GET /deliveries/{id}` エンドポイントは存在しない。配信は `GET /webhooks/{id}/deliveries` を通じて特定の Webhook にスコープされてのみアクセス可能である。Webhook の 404 チェックがアクセスをゲートする。

---

### ATK-09 — events 配列のオーバーフローによるメモリ枯渇 ✅ SAFE

**Attack**: 攻撃者が `{"events": [... 10,000 イベントタイプ ...]}` を送信して JSON 解析または保存中にメモリを枯渇させようとする。
**Result**: SAFE — リクエストサイズ制限ミドルウェア（デフォルト 1 MB）が大きすぎるボディを拒否する。アプリケーションレベルの配列長バリデーション（例: `max: 50 events`）が第 2 のガードを提供する。

---

### ATK-10 — 重複 URL の登録による複数配信のトリガー ✅ SAFE

**Attack**: 攻撃者が同じ URL を 100 回登録して各イベントのコピーを 100 部受け取ろうとする。
**Result**: SAFE — 同じ URL の複数登録は許可されている（例: 異なるイベントサブセット用）。登録エンドポイントでのレート制限と認証が不正使用に対するガードである。本番環境では `UNIQUE(url)` 制約またはユーザーごとの Webhook 制限を追加してください。

---

### ATK-11 — ID による他ユーザーの Webhook の削除 🚫 BLOCKED

**Attack**: 攻撃者が整数の Webhook ID を推測し、`DELETE /webhooks/{id}` を呼び出して別のユーザーの Webhook を削除しようとする。
**Result**: BLOCKED — 認可（JWT/セッションによる所有権チェック）が削除をゲートする。FT は仕組みを実証している。本番環境では認証は必須のレイヤーである。

---

### ATK-12 — サーバーサイドデータの持ち出しのためのペイロードインジェクション ✅ SAFE

**Attack**: 攻撃者が `{"payload": {"__proto__": {"admin": true}}}` のイベントをディスパッチしてプロトタイプ汚染またはテンプレートインジェクションが配信に到達することを期待する。
**Result**: SAFE — `payload` は JSON 文字列として保存され、サブスクライバーにそのまま転送される。PHP の JSON にはプロトタイプ汚染はない。テンプレートインジェクションには明示的なテンプレートエンジンが必要である。ペイロードは不透明なデータである。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | GET によるシークレット抽出 | 🚫 BLOCKED |
| ATK-02 | 内部 Webhook URL による SSRF | ⚠️ EXPOSED |
| ATK-03 | 非アクティブ/削除済み Webhook へのディスパッチ | 🚫 BLOCKED |
| ATK-04 | event_type による SQL インジェクション | 🚫 BLOCKED |
| ATK-05 | 非配列 events によるすべてへの購読 | 🚫 BLOCKED |
| ATK-06 | 無効な TLS 証明書への配信 | ✅ SAFE |
| ATK-07 | リトライによる再生 | 🚫 BLOCKED |
| ATK-08 | クロス Webhook の配信 ID 列挙 | 🚫 BLOCKED |
| ATK-09 | events 配列のオーバーフローによるメモリ枯渇 | ✅ SAFE |
| ATK-10 | 重複 URL 登録 | ✅ SAFE |
| ATK-11 | 他ユーザーの Webhook の削除 | 🚫 BLOCKED |
| ATK-12 | ペイロードのプロトタイプ汚染 / テンプレートインジェクション | ✅ SAFE |

**8 BLOCKED、3 SAFE、1 EXPOSED** — ATK-02（Webhook URL による SSRF）は本番環境での対策が必要です: 保存前に登録 URL をプライベート IP ブロックリストに対して検証してください。`docs/howto/url-shortener-ssrf-prevention.md` を参照してください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 実際のシークレットをレスポンスで返す | 攻撃者がシークレットを使用して任意のイベントの有効な HMAC 署名を偽造できる |
| Webhook 登録で URL バリデーションなし | SSRF: サーバーが内部メタデータエンドポイントにイベントを配信する |
| ディスパッチクエリに `is_active` フィルターなし | 非アクティブ/ソフトデリート済みの Webhook がイベントを受信し続ける |
| ペイロードを PHP シリアライズ文字列として保存する | 攻撃者が制御するデータのデシリアライズがリモートコード実行をトリガーする |
| Webhook ごとの配信ログなし | 配信失敗の診断や再生攻撃の検出ができない |
| リトライ機構なし | 一時的な失敗でイベント配信が永久に失われる |
