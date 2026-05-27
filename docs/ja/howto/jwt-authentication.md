# ハウツー: JWT 認証

> **FT リファレンス**: FT261 (`NENE2-FT/jwtlog`) — Argon2id パスワードハッシュと BearerTokenMiddleware を使った JWT 認証
> **VULN**: FT261 — 脆弱性評価（V-01 〜 V-10）

`LocalBearerTokenVerifier` と `BearerTokenMiddleware` を使った JWT Bearer トークンの発行と検証。

---

## クイックスタート

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// /auth/login 以外のすべてのパスを保護する
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## トークンの発行

`LocalBearerTokenVerifier` は `TokenIssuerInterface` と `TokenVerifierInterface` の両方を実装しています — 1 つのインスタンスで両方を処理します。

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // subject: ユーザー識別子（int または string）
    'email' => $user->email,    // カスタムクレーム
    'iat'   => $now,            // issued-at（Unix タイムスタンプ — int）
    'exp'   => $now + 3600,     // expiry   （Unix タイムスタンプ — int、有効期限機能に必要）
]);
```

**`exp` は Unix タイムスタンプ（int）でなければなりません。** 日付文字列（`'2026-06-01'`）を渡すと、`LocalBearerTokenVerifier` が比較前に `is_int($claims['exp'])` をチェックするため、有効期限の強制がサイレントにスキップされます。

---

## ハンドラーでのクレーム読み取り

`BearerTokenMiddleware` は検証成功後、デコードされたクレームを `nene2.auth.claims` リクエスト属性に格納します:

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // この null ガードはトリガーされないはず — ミドルウェアが既に欠落トークンを拒否している。
    // PHPStan level 8 と防御的な明確さのために含める。
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

`$request->getAttribute('nene2.auth.credential_type')` も利用可能で、`'bearer'` を返します。

---

## パス保護モード

`BearerTokenMiddleware` は 3 つのモードをサポートします — 最初の空でない設定が使われます:

| 設定 | 動作 | 使用タイミング |
|------|------|-------------|
| `protectedPaths: ['/me', '/admin']` | リストされた正確なパスのみ保護する | 公開パスが大多数の場合 |
| `protectedPathPrefixes: ['/api/']` | プレフィックスで始まるパスを保護する | サブツリー全体を保護する場合 |
| `excludedPaths: ['/login', '/register']` | リストされたパス以外のすべてを保護する | 公開パスが少数の場合 |
| （デフォルト — すべての配列が空） | すべてのパスを保護する | 完全にプライベートな API |

```php
// ✅ /auth/login は公開、それ以外はトークンが必要
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ /auth/me のみ保護される
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ すべての /api/ パスが保護される
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] は「何も保護しない」ではない — 許可リストモードを無効化し
//     次のモード（プレフィックス、次にブロックリスト、次に全保護）に落ちる。
```

---

## `alg: none` 攻撃 — 既に拒否済み

`LocalBearerTokenVerifier` は署名を検証する前にトークンヘッダーの `alg == 'HS256'` をチェックします。`none` を含む他のアルゴリズムは `TokenVerificationException` をスローします:

```
Token algorithm must be HS256.
```

これにより、攻撃者が署名なしのヘッダーレストークンを作成するクラシックな `alg: none` バイパスを防止します。カスタム検証器を実装する場合は、期待するアルゴリズムを常に明示的に強制してください。

---

## エラーレスポンス

`BearerTokenMiddleware` は 401 Problem Details を返し、`WWW-Authenticate` ヘッダーを自動的に追加します（RFC 6750）:

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

`error` の値: `missing_token`（ヘッダーなし）、`invalid_token`（不正なスキーム、不正な署名、期限切れ、`nbf` が未来、不正な形式）。

---

## シークレット管理

JWT シークレットをハードコードしないでください。環境変数から読み取ってください:

```php
// ❌ ハードコードされたシークレット — バージョン管理にコミットされる
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ 環境変数
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

すべての環境で強力なランダムシークレットを使用してください。本番では `LocalBearerTokenVerifier` の代わりにライブラリベースの実装（`firebase/php-jwt`、`lcobucci/jwt`）を使用してください — 「Local」プレフィックスはそのスコープを示しています。

---

## トークン失効

JWT はステートレスです — 組み込みの失効はありません。トークンは `exp` まで有効です。即時失効が必要な場合（例: ログアウト、パスワード変更）:

- `exp` と一致する TTL を持つ Redis でトークンブロックリストを保存する
- または短命のトークン（15 分）とリフレッシュトークンを使用する

---

## `authMiddleware` パラメーター名

`RuntimeApplicationFactory` の名前付きパラメーターは `middlewares:` または `middleware:` ではなく `authMiddleware:` です:

```php
// ❌ 不明な名前付きパラメーター $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ 正しい
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## コードレビューチェックリスト

- [ ] `exp` クレームは Unix タイムスタンプ（int）であり、日付文字列ではない
- [ ] JWT シークレットは環境変数から読み取る（ハードコードしない）
- [ ] `LocalBearerTokenVerifier` は本番では使用しない（ライブラリ実装を使用）
- [ ] `nene2.auth.claims` 属性は使用前に null チェックされている
- [ ] `excludedPaths` / `protectedPaths` モードの選択が意図と一致している
- [ ] トークンレスポンスに `password_hash` などのシークレットが含まれていない
- [ ] `Authorization` ヘッダーがログに記録されない
- [ ] 認証失敗には 401 が返される（404 ではない）

---

## タイミング攻撃保護: ユーザー列挙のためのダミーハッシュ

メールが見つからないとき、`$user === null` です。ダミーハッシュなしでは、コードは `password_verify()` を完全にスキップしてしまいます — 不明なメールのレスポンスが著しく速くなります。

```php
$user = $this->repo->findByEmail(trim($body['email']));

// 常に password_verify を実行する — タイミングベースのユーザー列挙を防止。
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  順序が重要: password_verify() は || $user === null より前に
// 短絡評価は $user が先にチェックされると password_verify() をスキップする。
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // メールが不明かパスワードが間違っているかに関わらず同じエラー
}
```

---

## VULN — 脆弱性評価（FT261）

### V-01 — ログインにブルートフォース保護なし

**リスク**: `POST /auth/login` にレート制限なし。

**影響**: 攻撃者は無制限のログイン試行を送信できます。Argon2id は意図的に低速（〜100ms）ですが、レート制限なしでは分散リクエストで何千ものパスワードを試せます。

**判定**: ⚠️ EXPOSED — `POST /auth/login` に `ThrottleMiddleware` を追加してください（例: 5 req/min/IP）。`Retry-After` 付きで 429 を返してください。

---

### V-02 — JWT シークレット強度は環境依存

**リスク**: `NENE2_LOCAL_JWT_SECRET` が空または弱い（`secret`、`test`）場合、HMAC-HS256 トークンはブルートフォースまたは推測可能です。管理者クレームを持つ偽造トークンが受け入れられます。

**判定**: ⚠️ EXPOSED — フェイルクローズの起動チェック:
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — トークン失効なし

**リスク**: 発行された JWT は `exp` まで有効です。盗まれたトークン、または削除されたユーザーのトークンが最大 1 時間受け入れ続けられます。

**判定**: ⚠️ EXPOSED — トークンブロックリストを実装してください（例: `revoked_tokens(jti TEXT PK, revoked_at TEXT)`）または短命のトークン（15 分）とリフレッシュトークンを使用してください。

---

### V-04 — ユーザー登録エンドポイントなし

**リスク**: `POST /auth/register` ルートが存在しません。テストユーザーには直接 DB 挿入が必要で、アプリケーションが強制するパスワードハッシュポリシーをバイパスします。

**判定**: DESIGN GAP — メールバリデーションと Argon2id ハッシュを使った `POST /auth/register` を追加してください。

---

### V-05 — メールの大文字小文字区別: 正規化なし

**リスク**: `WHERE email = ?` は大文字小文字を区別します。`USER@EXAMPLE.COM` と `user@example.com` は異なるルックアップです。異なるケースの 2 つのアカウントが共存できます。

**判定**: ⚠️ EXPOSED — 登録とログインで `strtolower()` を使ってメールを小文字に正規化してください。

---

### V-06 — トークン TTL: 1 時間は機密 API には長すぎる可能性がある

**リスク**: `TOKEN_TTL_SECONDS = 3600`。盗まれたトークンは最大 1 時間有効です。

**判定**: DESIGN CONSIDERATION — 1 時間はほとんどの API で許容されます。機密操作には短い TTL（5〜15 分）とリフレッシュトークンを使用してください。TTL を設定可能にしてください。

---

### V-07 — `password_hash` が JWT クレームにない

**リスク**: `issue()` 呼び出しには `sub`、`email`、`iat`、`exp` のみ含まれます。

**判定**: ✅ SAFE — クレームは最小限です。トークンがデコードされても（base64、暗号化ではない）、機密な内部データは公開されません。

---

### V-08 — メール経由の SQL インジェクション

**攻撃**: `{"email": "' OR '1'='1", "password": "x"}`

**観察結果**: `WHERE email = ?` はパラメーター化クエリです。インジェクションはリテラル文字列として扱われます。ユーザーは見つかりません。401 が返されます。

**判定**: 🚫 BLOCKED — パラメーター化クエリが SQL インジェクションを防止します。

---

### V-09 — メールフォーマットバリデーションなし

**リスク**: 空でない任意の文字列がメールとして受け入れられます（例: `"not-an-email"`）。

**影響**: Argon2id の無駄な計算、DB 内の無効なユーザー、パスワードリセットフローの破損。

**判定**: ⚠️ EXPOSED — 登録とログインで `filter_var($email, FILTER_VALIDATE_EMAIL)` を追加してください。

---

### V-10 — HTTPS 強制なし

**リスク**: JWT トークンとパスワードが HTTP でプレーンテキストで送信されます。

**判定**: ⚠️ EXPOSED — 本番で HTTPS を強制してください。`SecurityHeadersMiddleware` で `Strict-Transport-Security` ヘッダーを追加してください。

---

## VULN サマリー

| # | 脆弱性 | 判定 |
|---|--------|------|
| V-01 | ブルートフォース保護なし | ⚠️ EXPOSED |
| V-02 | JWT シークレット強度（環境依存） | ⚠️ EXPOSED |
| V-03 | トークン失効なし | ⚠️ EXPOSED |
| V-04 | 登録エンドポイントなし | DESIGN GAP |
| V-05 | メールの大文字小文字区別/正規化なし | ⚠️ EXPOSED |
| V-06 | トークン TTL 1 時間 | DESIGN CONSIDERATION |
| V-07 | password_hash が JWT クレームにない | ✅ SAFE |
| V-08 | メール経由の SQL インジェクション | 🚫 BLOCKED |
| V-09 | メールフォーマットバリデーションなし | ⚠️ EXPOSED |
| V-10 | HTTPS 強制なし | ⚠️ EXPOSED |

**本番前の重要な修正**:
1. **V-01** — `POST /auth/login` に `ThrottleMiddleware`（5 req/min/IP）
2. **V-02** — 起動時のフェイルクローズ JWT シークレットバリデーション（`strlen >= 32`）
3. **V-03** — トークン失効リストまたは短い TTL + リフレッシュトークン
4. **V-05** — 登録とログインでメールを小文字に正規化する
5. **V-09** — 登録時に `filter_var($email, FILTER_VALIDATE_EMAIL)`

---

## 関連 howto

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — PIN 検証のブルートフォースロックアウト
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — レート制限ミドルウェア
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + タイミングセーフ比較
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 明示的 DTO ホワイトリスト
