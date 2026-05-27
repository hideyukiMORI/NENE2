# ハウツー: Bearer トークン認証の使用方法

NENE2 は JWT ベースの認証のために `BearerTokenMiddleware` と `LocalBearerTokenVerifier` を提供します。このガイドでは、セットアップ、設定、トークンの発行、よくある落とし穴について説明します。

## セットアップ

`authMiddleware` という名前付きパラメーターを使って、`RuntimeApplicationFactory` にミドルウェアを組み込んでください:

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: 'change-me';
$verifier = new LocalBearerTokenVerifier($secret);
$bearer   = new BearerTokenMiddleware($problemDetails, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearer, // ← 名前付きパラメーターは authMiddleware（middlewares ではない）
))->create();
```

> **注意:** パラメーター名は `authMiddleware` であり、`middlewares` ではありません。`middlewares:` を使用すると、実行時に `Error: Unknown named parameter` が発生します。

## 全ルート保護 vs 選択的保護

`BearerTokenMiddleware` は 4 つのパスマッチングモードをサポートします（最初にマッチしたものが適用されます）:

```php
// 1. 特定のパスのみ保護する（allowlist）
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. プレフィックスで始まるパスを保護する（プレフィックス allowlist）
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. リスト以外のすべてを保護する（blocklist — よく使われるパターン）
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. すべてのパスを保護する（デフォルト — 配列なし）
new BearerTokenMiddleware($problems, $verifier);
```

## ハンドラーでのクレーム読み取り

検証が成功すると、クレームはリクエスト属性として保存されます:

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

クレデンシャルタイプは別途保存されます:

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## トークンの発行（ローカル / テスト用）

`LocalBearerTokenVerifier` は `TokenIssuerInterface` も実装しています:

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← 常に exp を含めること
]);
```

> **常に `exp` を含めてください。** `exp` のないトークンは有効期限なしとして扱われます。テストでは問題ありませんが、そのようなトークンが本番に到達すると危険です。`exp` がない場合、バリデーターは有効期限チェックをスキップします。

## エラーレスポンス

失敗時、`BearerTokenMiddleware` は `WWW-Authenticate` ヘッダー付きの `401 Unauthorized` Problem Details レスポンスを返します:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

`WWW-Authenticate` のエラーコード:
- `missing_token` — `Authorization` ヘッダーなし
- `invalid_token` — 不正なスキーム、期限切れ、無効な署名、不正な形式、誤ったアルゴリズム、`nbf` が未来

## `LocalBearerTokenVerifier` のセキュリティ特性

| 脅威 | 防御策 |
|--------|-----------|
| 署名偽造 | HMAC-HS256、定時間 `hash_equals` |
| アルゴリズム置換（`alg:none`） | `HS256` のみ受け入れる |
| 期限切れトークン | `exp` クレームを確認 |
| 未来有効トークン | `nbf` クレームを確認 |
| ペイロード改ざん | 署名がヘッダー + ペイロードをカバー; 改ざんで署名が無効化される |

> `LocalBearerTokenVerifier` はローカル開発とテスト向けに設計されています。本番環境では、キーローテーションと非対称アルゴリズムをサポートする `TokenVerifierInterface` のライブラリバック実装（例: firebase/php-jwt）を注入してください。

## テストパターン

```php
// setUp(): テスト用シークレットでバリデーターを作成する
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// ユーザーの有効なトークンを発行する
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// 期限切れトークンを発行する（ネガティブテスト用）
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// まだ有効でないトークンを発行する
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
