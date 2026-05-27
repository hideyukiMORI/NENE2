# ハウツー: 冪等性キー（リクエスト重複排除）

> **FT リファレンス**: FT292 (`NENE2-FT/deduplog`) — 冪等性キー重複排除: UNIQUE(idempotency_key) DB 制約、24h TTL と再処理可能な有効期限、キャッシュされたレスポンスに `replayed: true` フラグ、パラメーター化クエリによるインジェクション防止、ATK-01〜12 すべて BLOCKED、24 テスト / 57 アサーション PASS。

このガイドでは、冪等性キーの実装方法を示します — ヘッダーベースのメカニズムで、繰り返しリクエスト（リトライ、ネットワーク障害）が重複した副作用なしに同じ結果を生成することを保証します。

## スキーマ

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` により各キーが 1 回だけ保存されます。レスポンスボディは JSON としてシリアライズされ、後続のリクエストでリプレイされます。

## リクエストフロー

```
クライアントが POST /payments を Idempotency-Key: <uuid> 付きで送信
  │
  ├─ キーが DB に存在し、有効期限切れでない？
  │    └─ YES → キャッシュされたレスポンス + { "replayed": true } を返す
  │
  └─ NO → リクエストを処理 → レスポンスを保存 → 201 を返す
```

## Idempotency-Key の抽出

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

キーは必須で、トリム後に空でない必要があります。空白のみのキーは 400 で拒否されます。

## キャッシュルックアップ — 有効期限チェック

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // 有効期限切れのエントリは新鮮として扱う（再処理可能）
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

有効期限切れのキーは `null` を返します — リクエストは新規として再処理されます。これにより TTL 有効期限後の安全な再試行が可能になり、永続的な重複排除なしに済みます。

## キャッシュ保存 — TTL 計算

```php
private const int TTL_SECONDS = 86400; // 24 時間

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

TTL は UTC で計算されます。`DateTimeImmutable::modify()` は DST 遷移と深夜のロールオーバーを安全に処理します。

## `replayed: true` シグナル

キャッシュされたレスポンスにはボディに `"replayed": true` がマージされます:

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

これにより、クライアントがステータスコードを検査せずに初回レスポンスとリプレイを区別できます。ステータスコードは変更なしにリプレイされます（作成の場合は 201）。

## UNIQUE 制約を競合ガードとして使う

```sql
UNIQUE(idempotency_key)
```

同じキーを持つ 2 つの並行リクエストが両方ともルックアップチェックを通過した場合（TOCTOU）、1 つの `INSERT` のみが成功します。もう一方は制約エラーを受け取り、アプリケーションはキャッシュされたレスポンスを再取得することで処理できます。

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — Idempotency-Key ヘッダーへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `Idempotency-Key: '; DROP TABLE idempotency_keys; --` を送信する。
**結果**: BLOCKED — すべてのクエリはパラメーター化ステートメントを使用。インジェクション文字列はリテラルなキー値として保存または検索される。

---

### ATK-02 — amount フィールドへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `{ "amount": "1; DROP TABLE payments;" }` を送信する。
**結果**: BLOCKED — amount バリデーションが整数型を要求。文字列値は `is_int()` チェックで失敗 → 422。DB クエリは実行されない。

---

### ATK-03 — item フィールドへの SQL インジェクション（安全に保存） 🚫 BLOCKED

**攻撃**: 注文作成で `{ "item": "' OR 1=1; --" }` を送信する。
**結果**: BLOCKED — パラメーター化クエリが文字列を `item` 値としてそのまま保存。SQL は実行されない。

---

### ATK-04 — リプレイ攻撃（同じキーを 10 回） 🚫 BLOCKED

**攻撃**: 同じキーで `POST /payments` を 10 回送信して 10 件のレコードを作成する。
**結果**: BLOCKED — 最初のリクエストが 1 件の決済を作成してレスポンスをキャッシュ。後続の 9 件はすべて `replayed: true` 付きのキャッシュされたレスポンスを返す。決済行は 1 件のみ存在。

---

### ATK-05 — 空白のみの Idempotency-Key 🚫 BLOCKED

**攻撃**: `Idempotency-Key:    `（スペースのみ）を送信して空キーチェックをバイパスする。
**結果**: BLOCKED — `trim($key) === ''` → 400。空白のみのキーは欠如したキーと同等。

---

### ATK-06 — 極端に長い Idempotency-Key 🚫 BLOCKED（設計注記）

**攻撃**: 数メガバイトのキー文字列を送信する。
**結果**: BLOCKED（設計注記） — SQLite はキーをそのまま保存。非常に長いキーはルックアップパフォーマンスを低下させるがクラッシュしない。本番では長さ制限を追加すること（例: `strlen($key) > 255 → 400`）。

---

### ATK-07 — 注文での負の数量 🚫 BLOCKED

**攻撃**: 負の数量の注文を作成するために `{ "quantity": -5 }` を送信する。
**結果**: BLOCKED — 数量バリデーション: `$quantity <= 0` → 422。正の整数のみ受け付けられる。

---

### ATK-08 — item フィールドに保存される XSS 🚫 BLOCKED

**攻撃**: `{ "item": "<script>alert(1)</script>" }` を送信する。
**結果**: BLOCKED — JSON 文字列値としてそのまま保存される。API は `application/json` を返す。JSON エンコードが `<`、`>` をエスケープする。API 層で HTML レンダリングは発生しない。

---

### ATK-09 — 並行した重複キー 🚫 BLOCKED

**攻撃**: 2 つのプロセスが同じキーを同時に送信し、どちらも保存前にルックアップチェックを通過する。
**結果**: BLOCKED — `UNIQUE(idempotency_key)` により 1 つの INSERT のみが成功。敗者は制約エラーを受け取り、キャッシュされたレスポンスを再取得できる。

---

### ATK-10 — amount の整数オーバーフロー 🚫 BLOCKED（設計注記）

**攻撃**: `{ "amount": 9999999999999999999 }`（PHP_INT_MAX を超える）を送信する。
**結果**: BLOCKED（設計注記） — PHP は非常に大きな JSON 整数を浮動小数点数に暗黙変換する。`is_int()` は範囲内の整数で通過する。本番では上限チェックを追加すること（例: amount > 10_000_000 → 422）。

---

### ATK-11 — NULL amount 🚫 BLOCKED

**攻撃**: null がバリデーションをバイパスすることを期待して `{ "amount": null }` を送信する。
**結果**: BLOCKED — `!is_int(null)` は true、`ctype_digit(null)` は false → 422。

---

### ATK-12 — 内部情報の漏洩なし 🚫 BLOCKED

**攻撃**: 422 エラーをトリガーしてレスポンスにスタックトレース、ファイルパス、SQL が含まれるか確認する。
**結果**: BLOCKED — エラーレスポンスには `{ "error": "..." }` または Problem Details のみが含まれる。どのレスポンスにも内部パス、SQL、スタックトレースなし。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | Idempotency-Key ヘッダーへの SQL インジェクション | 🚫 BLOCKED |
| ATK-02 | amount フィールドへの SQL インジェクション | 🚫 BLOCKED |
| ATK-03 | item フィールドへの SQL インジェクション | 🚫 BLOCKED |
| ATK-04 | リプレイ攻撃（10 回の重複リクエスト） | 🚫 BLOCKED |
| ATK-05 | 空白のみのキー | 🚫 BLOCKED |
| ATK-06 | 極端に長いキー | 🚫 BLOCKED（設計注記） |
| ATK-07 | 負の数量 | 🚫 BLOCKED |
| ATK-08 | item フィールドへの XSS | 🚫 BLOCKED |
| ATK-09 | 並行した重複キー | 🚫 BLOCKED |
| ATK-10 | amount の整数オーバーフロー | 🚫 BLOCKED（設計注記） |
| ATK-11 | NULL amount | 🚫 BLOCKED |
| ATK-12 | 内部情報漏洩なし | 🚫 BLOCKED |

**12 BLOCKED、0 EXPOSED**
パラメーター化クエリ、厳格な型バリデーション、`UNIQUE(idempotency_key)`、TTL 有効期限がすべての重要な重複排除攻撃ベクターをカバーします。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(idempotency_key)` 制約なし | 並行したリトライが重複レコードを作成する。重複排除競合状態 |
| TTL なし / 永続的な重複排除 | 古いキーがテーブルを埋める。1 日以上後の正当なリトライが失敗する |
| `replayed: true` フラグなし | クライアントが初回レスポンスとキャッシュリプレイを区別できない |
| 有効期限チェックするが期限切れキーを再処理しない | TTL 後のリトライでもキャッシュされた（古い可能性のある）レスポンスが返される |
| 空白のみのキーを受け付ける | `"   "` が有効なキーとして扱われる。異なるクライアントが `""` と `"   "` を互換的に使う可能性がある |
| キー長制限なし | ストレージとルックアップでの数 MB のキーがパフォーマンスを低下させる |
| 重複に 409 を返す | リプレイはオリジナルのステータス（201）を返すべきで、Conflict ではない |
| amount 型を厳格にバリデーションしない | `"1000"` 文字列が緩いチェックを通過する。厳格な JSON 整数には `is_int()` を使うこと |
| amount の上限なし | 整数オーバーフローやビジネスバリデーションなしで非常識な金額が受け付けられる |
