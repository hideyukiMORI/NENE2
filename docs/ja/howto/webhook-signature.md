# Webhook 署名検証

外部サービス（Stripe、GitHub 等）から Webhook を受信する際は、ペイロードを処理する前に必ず署名を検証してください。これにより Webhook が期待された送信者から来ており、ボディが改ざんされていないことを証明します。

## 署名ヘッダーフォーマット

Stripe 互換フォーマットを使用してください:

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

署名ペイロードは `"<timestamp>.<rawBody>"` です — タイムスタンプはヘッダーだけでなく署名コンテンツにも含まれます。これによりタイムスタンプがボディに結びつけられます: 攻撃者が有効期限チェックをバイパスするためにタイムスタンプを変更すると、署名が無効になります。

## 重要なルール: `hash_equals()` を使用し、`===` は絶対に使わない

```php
// ❌ タイミング攻撃に脆弱
if ($expectedSig === $receivedSig) { ... }

// ✅ 定時間比較 — これを使用する
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**`===` が危険な理由:** PHP の `===` は最初の不一致文字で短絡します。何千ものリクエストを実行してレスポンス時間を測定できる攻撃者は、予想される署名の何文字が推測とマッチするかを — 1 バイトずつ — 学習してシークレットをブルートフォースできます。これがタイミング攻撃です。

`hash_equals()` は文字列がどこで分岐するかに関わらず常にすべての文字を比較するため、レスポンス時間はシークレットについて何も明かしません。これは PHP 5.6 から存在します。

このバグは PHPStan、静的解析ツール、標準テストでは検出できません。コードレビューが唯一のゲートです。

## 実装

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // 5 分のリプレイウィンドウ

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException 欠落、不正な形式、期限切れ、または不一致の場合
     */
    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);
        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        if (!hash_equals($expectedSig, $receivedSig)) { // ← 定時間
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** アウトバウンド Webhook（とテスト）のヘッダー値を生成する。 */
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    /** @return array{timestamp: int, signature: string} */
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

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }
}
```

## コントローラーへの組み込み

JSON 解析の前に生ボディを読み取ってください — 署名は生のバイト列の上で計算されます:

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create(
            $request,
            'invalid-signature',
            'Invalid webhook signature.',
            401,          // 401: 送信者の身元が検証できなかった
            $e->getMessage(),
        );
    }

    // 検証後に安全に解析できる
    $body = json_decode($rawBody, true);
    // ...
}
```

401 を返してください（403 ではなく）: 送信者の身元が検証できなかったのは認証の失敗であり、認可の失敗ではありません。

## シークレット管理

Webhook シークレットは環境変数に保存してください。コードには絶対に入れないこと:

```php
// config ローダー内
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## リプレイ攻撃防止

5 分のタイムスタンプウィンドウ（`TOLERANCE_SECONDS = 300`）は以下を意味します:

- 有効な Webhook を傍受した攻撃者は 5 分以上経過後には再生できない。
- 送信者と受信者間の現実の時計のずれ（通常 < 30 秒）は許容される。
- `abs(time() - $timestamp)` は過去と未来のタイムスタンプの両方を処理するため、どちらの方向の小さな時計のずれも受け入れられる。

## シークレットのローテーション

シークレットのローテーション中は、移行期間中に古いシークレットと新しいシークレットの両方からの署名を受け入れてください:

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets 最初が現在のシークレット、2 番目が前のシークレット */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // 最初にマッチしたものが勝つ
            } catch (SignatureException) {
                // 次のシークレットを試す
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## テスト

`WebhookVerifier` の `sign()` メソッドを使って署名済みのテストリクエストを簡単に構築できます:

```php
private function signedPost(array $payload, int $timestamp): ResponseInterface
{
    $rawBody   = json_encode($payload, JSON_THROW_ON_ERROR);
    $verifier  = new WebhookVerifier('test-secret');
    $sigHeader = $verifier->sign($rawBody, $timestamp);

    $stream  = Stream::create($rawBody);
    $request = (new ServerRequest('POST', '/webhook'))
        ->withHeader('X-Webhook-Signature', $sigHeader)
        ->withBody($stream);
    return $this->app->handle($request);
}

// リプレイ防止のテスト
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// 改ざんされたボディのテスト
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## コードレビューチェックリスト

- [ ] `hash_equals()` が署名比較に使用されており、`===` や `==` ではない
- [ ] タイムスタンプが署名ペイロードに含まれている（`"<t>.<body>"`）、ヘッダーだけでなく
- [ ] タイムスタンプウィンドウが強制されている（`abs(time() - $timestamp) > TOLERANCE`）
- [ ] 生ボディが解析前に読み取られ、署名が生のバイトに対して検証されている
- [ ] Webhook シークレットが環境変数から来ており、ハードコードされていない
- [ ] 署名の失敗に 401 が返されている（403 や 400 ではない）
- [ ] エラーメッセージがシークレットや期待される署名値を露出していない
- [ ] テストが以下をカバーしている: 有効な署名、誤ったシークレット、改ざんされたボディ、期限切れのタイムスタンプ、欠落したヘッダー
