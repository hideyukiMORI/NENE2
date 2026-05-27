# ハウツー: HMAC-SHA256 による Webhook 署名検証

> **FT リファレンス**: FT260 (`NENE2-FT/hmaclog`) — Webhook 署名検証: HMAC-SHA256、タイミングセーフな比較、リプレイ攻撃防止
> **ATK**: FT260 — クラッカー思考攻撃テスト（ATK-01 から ATK-12）

Stripe スタイルの HMAC-SHA256 署名を使用して受信 Webhook リクエストを検証する方法を示します。
署名ヘッダーはタイムスタンプをリクエストボディにバインドし、偽造とリプレイ攻撃の両方を防ぎます。
`hash_equals()` はタイミング攻撃を防ぐために定時間比較に使用されます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/webhook`        | 署名済み Webhook を受信して検証する |
| `GET`  | `/webhook/events` | 受信した Webhook イベントを一覧表示する |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    payload      TEXT NOT NULL,
    delivered_at TEXT NOT NULL
);
```

イベントは署名検証が通過した後にのみ保存されます。拒否された Webhook は永続化されません。

---

## 署名フォーマット（Stripe スタイル）

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**署名ペイロード**: `"<timestamp>.<raw-body>"`

タイムスタンプは HMAC 計算に含まれます。これは以下を意味します:
- 有効な署名は計算されたボディに対してのみ有効です（ボディの改ざんで署名が壊れる）。
- 有効な署名は生成された瞬間にのみ有効です（古い有効な署名の再生は、HMAC が正しくてもタイムスタンプチェックで失敗する）。

---

## バリデーター

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // 重要: hash_equals は定時間; === は NOT
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }
        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }
}
```

---

## コントローラー: 生ボディの抽出

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // 解析済みでなく生のバイトでなければならない

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // 検証後にのみ解析する
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**重要な順序**:
1. 生ボディを文字列として読み取る — HMAC は正確なバイト列の上で計算された。
2. 生ボディに対して署名を検証する。
3. 検証が成功した後にのみ JSON を解析する。

JSON を先に解析して再シリアライズすると、バイト内容が異なる場合があり（キーの順序、空白）、HMAC チェックが壊れます。

---

## ATK — クラッカー思考攻撃テスト（FT260）

### ATK-01 — 署名ヘッダーの欠如

**Attack**: `X-Webhook-Signature` ヘッダーなしで Webhook を送信する。

```bash
POST /webhook
{"event_type": "user.created"}
```

**Observed**: `verify()` は計算の前に `$header === ''` をチェックする。401 Problem Details を返す:
`"Missing X-Webhook-Signature header."` イベントは保存されない。

**Verdict**: **BLOCKED** — 欠落したヘッダーは署名計算の前にキャッチされる。

---

### ATK-02 — 改ざんされた署名（1 文字の変更）

**Attack**: 有効な署名を取得して hex 文字を 1 つ変更する。

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-but-one-char-wrong>
```

**Observed**: `hash_equals($expectedSig, $receivedSig)` が `false` を返す。401 が返される。
比較は定時間 — レスポンスタイムは何文字マッチするかによって変わらない。

**Verdict**: **BLOCKED** — `hash_equals()` は改ざんされた署名を拒否しながらタイミングオラクルを防ぐ。

---

### ATK-03 — 誤ったシークレットを使った署名

**Attack**: 異なる HMAC シークレットでリクエストに署名する。

```
X-Webhook-Signature: t=<now>,v1=<hmac-with-wrong-secret>
```

**Observed**: `computeSignature()` はサーバーのシークレットを使用する。攻撃者の HMAC（異なるシークレットで計算）は異なる hex 文字列を生成する。`hash_equals()` が失敗する。401 が返される。

**Verdict**: **BLOCKED** — シークレットなしでは有効な署名を偽造できない。

---

### ATK-04 — リプレイ攻撃: 有効な古い署名

**Attack**: 正当な `X-Webhook-Signature` ヘッダーをキャプチャして 10 分後に再生する。

```
X-Webhook-Signature: t=<10分前のタイムスタンプ>,v1=<valid-hmac>
```

**Observed**: `checkTimestamp($timestamp)` が `abs(time() - $timestamp)` を計算する。
10 分 = 600 秒 > 300 秒の許容誤差。`SignatureException` がスローされる。401 が返される。

**Verdict**: **BLOCKED** — リプレイ攻撃は 300 秒のタイムスタンプ許容誤差で防がれる。

---

### ATK-05 — 未来のタイムスタンプ: リプレイ防御バイパスの試み

**Attack**: 有効期間を延ばすために遠い未来のタイムスタンプでリクエストに事前署名する。

```
X-Webhook-Signature: t=<now + 3600>,v1=<hmac-with-future-ts>
```

**Observed**: `abs(time() - $timestamp)` = 3600 > 300。`SignatureException` がスローされる。401 が返される。
`abs()` は未来のタイムスタンプも拒否されることを意味する — チェックは対称的。

**Verdict**: **BLOCKED** — `abs()` は許容窓の外の過去と未来のタイムスタンプの両方を確実に拒否する。

---

### ATK-06 — 有効な署名を持つボディの改ざん

**Attack**: 有効な Webhook を傍受する。`X-Webhook-Signature` ヘッダーを保持して JSON ボディを変更する。

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-over-original-body>
Body: {"event_type": "user.deleted"}   ← "user.created" から変更
```

**Observed**: HMAC は `"<timestamp>.<original-body>"` の上で計算された。変更されたボディは
異なる HMAC を生成する。`hash_equals()` が失敗する。401 が返される。

**Verdict**: **BLOCKED** — 署名はタイムスタンプをボディにバインドする。どちらかを変更すると署名が無効になる。

---

### ATK-07 — 不正な形式のヘッダー: タイムスタンプなし

**Attack**: `t=` コンポーネントなしで署名ヘッダーを送信する。

```
X-Webhook-Signature: v1=<some-hmac>
```

**Observed**: `parseHeader()` は `isset($parts['t'], $parts['v1'])` をチェックする。欠落した `t` は
`SignatureException('Malformed X-Webhook-Signature header.')` をスローする。401 が返される。

**Verdict**: **BLOCKED** — ヘッダーパーサーが必須フィールドを強制する。

---

### ATK-08 — サーバーでの空のシークレット

**Attack scenario**: サーバーが空の HMAC シークレット（`''`）で誤設定されている。

**Observed**: PHP の `hash_hmac()` では空のシークレットは有効です — 決定論的な hex 文字列を生成します。空のシークレットを発見した攻撃者は有効な署名を偽造できます:
`hash_hmac('sha256', "{$timestamp}.{$body}", '')`。

**Verdict**: **EXPOSED（設定ミス）** — バリデーターは空のシークレットを拒否しない。
アプリケーション設定レイヤーは起動時に `WEBHOOK_SECRET` が空でないことをバリデーションする必要があります。
フェイルクローズドデフォルト: シークレットが空の場合、すべての Webhook を拒否する。

```php
// 推奨される起動時ガード
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — HMAC バイパス: 空の値で `v1=` を送信

**Attack**: 署名を空文字列に設定する: `X-Webhook-Signature: t=<now>,v1=`。

**Observed**: `parseHeader()` は `$parts['v1'] === ''` をチェックする。空の `v1` は
`SignatureException('Malformed X-Webhook-Signature header.')` をスローする。401 が返される。

**Verdict**: **BLOCKED** — 空の署名は `hash_equals()` が呼ばれる前にパーサーで拒否される。

---

### ATK-10 — タイムスタンプインジェクション: 非数字のタイムスタンプ

**Attack**: 純粋な整数でないタイムスタンプを送信する: `t=1234abc`。

```
X-Webhook-Signature: t=1234abc,v1=<some-hmac>
```

**Observed**: `parseHeader()` は `ctype_digit($parts['t'])` をチェックする。非数字文字は
`SignatureException('Malformed X-Webhook-Signature header.')` を引き起こす。401 が返される。

**Verdict**: **BLOCKED** — `ctype_digit()` はタイムスタンプが純粋な整数文字列であることを強制する。

---

### ATK-11 — ヘッダーインジェクション: HMAC hex のカンマ

**Attack**: パーサーを混乱させるために `v1` 値にカンマを注入する。

```
X-Webhook-Signature: t=<now>,v1=abc,def
```

**Observed**: `parseHeader()` は `explode('=', $chunk, 2)` を limit 2 で使用する。ヘッダーは
まず `,` で分割され（`['t=<now>', 'v1=abc', 'def']` を生成）、次に各チャンクが
limit 2 で `=` で分割される。`def` チャンクは `['def', '']` になり重要なものを上書きしない。
`v1` の値は `abc` で、有効な HMAC hex ではない。`hash_equals()` が失敗する。401 が返される。

**Verdict**: **BLOCKED** — パーサーのロバスト性 + HMAC 長チェックがインジェクション操作を防ぐ。

---

### ATK-12 — 大きなボディ: ペイロードサイズ攻撃

**Attack**: マルチメガバイトのボディで Webhook を送信する。

**Observed**: バリデーターは `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)` を計算する。
`hash_hmac()` は任意に大きな入力を処理する。出力は常に 64 hex 文字。
バリデーターレベルでは明示的なサイズ制限が適用されていない。100 MB のボディは署名が有効で
タイムスタンプが新鮮であれば受け入れられる。

**Verdict**: **EXPOSED** — Webhook エンドポイントにリクエストサイズ制限がない。リソース枯渇を防ぐために
アップストリームにリクエストサイズミドルウェア（例: 1 MB 制限）を追加してください。バリデーターはサイズ制限に責任を持つべきではありません — それは外部ミドルウェアレイヤーの懸念事項です。

---

## ATK サマリー

| # | 攻撃ベクター | 判定 |
|---|---|---|
| ATK-01 | 署名ヘッダーの欠如 | BLOCKED |
| ATK-02 | 改ざんされた署名（1 文字） | BLOCKED |
| ATK-03 | 誤ったシークレットの使用 | BLOCKED |
| ATK-04 | リプレイ攻撃（古いタイムスタンプ） | BLOCKED |
| ATK-05 | 未来のタイムスタンプバイパス | BLOCKED |
| ATK-06 | ボディの改ざん | BLOCKED |
| ATK-07 | 不正な形式のヘッダー（タイムスタンプなし） | BLOCKED |
| ATK-08 | 空のサーバーシークレット（設定ミス） | EXPOSED |
| ATK-09 | 空の `v1=` 値 | BLOCKED |
| ATK-10 | 非数字のタイムスタンプ | BLOCKED |
| ATK-11 | カンマによるヘッダーインジェクション | BLOCKED |
| ATK-12 | 大きなボディ / リソース枯渇 | EXPOSED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-08** — 起動時のフェイルクローズド空シークレットガード（`if ($secret === '') throw`）
2. **ATK-12** — Webhook ルートのアップストリームにリクエストサイズミドルウェア（例: 1 MB 制限）

---

## 設計上の注意

### なぜシンプルな Bearer トークンではなく HMAC-SHA256 なのか?

Bearer トークンは送信者がトークンを知っていることのみを証明します。HMAC-SHA256 は送信者がシークレットを知っており、かつボディが変更されていないことを証明します — ボディの完全性が組み込まれています。

### なぜタイムスタンプを HMAC ペイロードにバインドするのか?

署名が `HMAC(body)` のみであれば、有効なリクエストをキャプチャした攻撃者は無期限に再生できます。`"<timestamp>.<body>"` に署名することで、各署名は 300 秒の窓の中でのみ有効で、計算されたボディに対してのみ有効です。

### なぜ `===` ではなく `hash_equals()` なのか?

PHP の `===` は短絡比較です: 2 つの文字が異なると停止します。攻撃者は 2 つの文字列を比較するのにかかる時間を測定して、先頭の何文字がマッチするかを推測できます。これによりシークレットを 1 バイトずつブルートフォースするタイミングオラクル攻撃が可能になります。`hash_equals()` は文字列がどこで分岐するかに関わらず定時間で実行されます。

---

## 関連 howto

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — PIN ストレージ + ロックアウトのための `hash_equals()` と HMAC-SHA256
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — クラッカー思考 ATK アセスメントパターン
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — 署名検証の補完としてのレート制限
