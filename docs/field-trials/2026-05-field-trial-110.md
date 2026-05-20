# Field Trial 110 — JWT Authentication (BearerTokenMiddleware / LocalBearerTokenVerifier)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/jwtlog/`
**NENE2 version:** 1.5.43
**Theme:** JWT 発行・検証・保護ルート — `LocalBearerTokenVerifier` による HMAC-HS256 JWT 発行・`BearerTokenMiddleware` による保護ルート、`alg: none` 攻撃拒否、有効期限検証、`nene2.auth.claims` request attribute からのクレーム取得。

---

## What was built

JWT 認証 API を実装した。

- `POST /auth/login` — メール + パスワードで認証し JWT トークンを返す。
- `GET /auth/me` — Bearer トークン必須の保護ルート。クレームからユーザー情報を返す。

---

## Findings

### 1. `LocalBearerTokenVerifier` は Issuer と Verifier を兼ねる（重要な発見）

`LocalBearerTokenVerifier` は `TokenVerifierInterface` と `TokenIssuerInterface` の両方を実装している。インスタンスを一つ作れば JWT の発行・検証に使える:

```php
use Nene2\Auth\LocalBearerTokenVerifier;

$verifier = new LocalBearerTokenVerifier($secret);

// JWT 発行
$token = $verifier->issue(['sub' => $user->id, 'email' => $user->email, 'exp' => time() + 3600]);

// JWT 検証
$claims = $verifier->verify($token); // TokenVerificationException if invalid
```

名前に "Verifier" とだけ付いているため "Issuer" でもあることが分かりにくい。PHPDoc を確認しないと見落とす。

---

### 2. `excludedPaths` vs `protectedPaths` vs `protectedPathPrefixes`（摩擦あり）

`BearerTokenMiddleware` には3種類のパスマッチングモードがある。**`/auth/login` のような公開パスが少数のとき**は `excludedPaths` を使う（デフォルトは全保護）:

```php
// ❌ protectedPaths = [] は「全保護」ではなく「全公開」になる（空＝allowlist無効）
$auth = new BearerTokenMiddleware($problems, $verifier, protectedPaths: []);

// ❌ protectedPaths に /auth/me だけ書くと /auth/login も保護されてしまう
$auth = new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ 公開パスが少数 → excludedPaths でログインだけ除外
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ 保護パスが少数 → protectedPaths で列挙
$auth = new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me', '/admin/...']);

// ✅ /me/* などダイナミックルート群 → protectedPathPrefixes
$auth = new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/me/']);
```

優先順序: `protectedPaths` > `protectedPathPrefixes` > `excludedPaths` > 全保護（デフォルト）。

---

### 3. `nene2.auth.claims` request attribute からのクレーム取得

`BearerTokenMiddleware` は検証済みクレームを以下の request attribute に格納する:

```php
// BearerTokenMiddleware が設定する属性
$request->getAttribute('nene2.auth.credential_type'); // 'bearer'
$request->getAttribute('nene2.auth.claims');           // array<string, mixed>

// ハンドラー側での取得
/** @var array<string, mixed>|null $claims */
$claims = $request->getAttribute('nene2.auth.claims');
$userId = $claims['sub'];   // subject (ユーザーID)
$email  = $claims['email']; // カスタムクレーム
```

attribute 名は文字列定数として公開されていないため、PHPDoc か BearerTokenMiddleware のソースを読まないと分からない。

---

### 4. JWT クレームの `exp`/`iat`/`sub` は Unix タイムスタンプ（int）

`LocalBearerTokenVerifier::verify()` は `exp` と `nbf` を int として比較する。文字列（ISO 8601 等）を渡しても検証は通るが、有効期限チェックが機能しない:

```php
// ❌ exp が文字列 — 型チェックで弾かれず、有効期限チェックが機能しない
$token = $verifier->issue([
    'sub' => $user->id,
    'exp' => '2026-05-22T00:00:00Z',  // is_int() が false → チェックスキップ
]);

// ✅ exp は Unix タイムスタンプ（int）
$token = $verifier->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

### 5. `alg: none` 攻撃は `LocalBearerTokenVerifier` が拒否する

`LocalBearerTokenVerifier` はヘッダーの `alg` フィールドを確認し、`HS256` 以外は `TokenVerificationException` を throw する:

```php
// alg: none トークン（署名なし）を試みる
$header  = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
$payload = base64_encode(json_encode(['sub' => 1, 'exp' => time() + 3600]));
$token   = rtrim(strtr($header, '+/', '-_'), '=') . '.' . rtrim(strtr($payload, '+/', '-_'), '=') . '.';

// → TokenVerificationException: "Token algorithm must be HS256."
$verifier->verify($token); // 401
```

JWT ライブラリの初期実装で `alg: none` 攻撃を許してしまうものが歴史的に多い。NENE2 の実装はこれを明示的に拒否する。

---

### 6. `WWW-Authenticate` ヘッダーの自動付与（RFC 6750）

`BearerTokenMiddleware` は 401 レスポンスに `WWW-Authenticate: Bearer ...` ヘッダーを自動で付与する。クライアントが認証の仕組みを自動検出できるようになる:

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

自分で 401 を返すときにこのヘッダーを忘れがちだが、ミドルウェアが担うため実装側は意識しなくてよい。

---

### 7. `authMiddleware` パラメータ名（前回 FT107 の教訓の再確認）

`RuntimeApplicationFactory` の named parameter は `authMiddleware:`（`middlewares:` や `middleware:` ではない）。`ThrottleMiddleware` 同様、PHPDoc を確認しないと間違えやすい:

```php
// ❌ 間違い
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ 正しい
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## Test results

14 tests, 53 assertions — all pass.

Key behaviors confirmed:
- `POST /auth/login` 正しい認証 → 200、JWT トークン・`expires_at`・`token_type: Bearer`
- JWT クレームに `sub`（int）、`email`、`iat`（int）、`exp`（int）が含まれる
- JWT 有効期限が 1 時間後
- 間違ったパスワード → 401
- 存在しないメール → 401（404 ではない）
- 必須フィールドなし → 400
- 有効な JWT → `GET /auth/me` が 200
- JWT なし → 401 + `WWW-Authenticate` ヘッダー
- 署名改ざん JWT → 401
- 期限切れ JWT → 401
- `Bearer` スキーム以外（`Basic` 等） → 401
- `alg: none` JWT → 401
- `/auth/login` は認証不要（`excludedPaths` で除外）
- `/auth/me` レスポンスに `password_hash` を含まない

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1年・PHP 独学中）

**JWT の概念自体が難しい:** ヘッダー・ペイロード・署名の3部構造、Base64URL エンコード、HMAC-HS256 署名、`exp`/`iat`/`sub` の意味を理解していない段階では何を作っているのか分からない。「なぜセッション Cookie ではなく JWT なのか」という根本的な疑問も出る。

**`alg: none` 攻撃:** 攻撃の意味を理解できないため、「なぜわざわざこれを拒否するのか」が伝わらない。「署名なしのトークンを誰でも作れてしまう」という具体的なシナリオで説明が必要。

**事故リスク:** 高。セッション + Cookie 方式とのトレードオフを理解せずに JWT を採用するリスクがある。

---

### ペルソナ2: ロースキル経験者（PHP 歴3〜4年・受託 Web 開発）

**`exp` に文字列を渡す:** `'2026-06-01'` のような日付文字列を `exp` に渡しても PHP は型エラーを出さず、検証が静かにスキップされる。「有効期限を設定したつもりが機能していない」状態になりやすい。

**`protectedPaths` の空配列誤解:** `protectedPaths: []` が「全保護なし（全公開）」ではなく「allowlist 無効（全保護）」だと理解しにくい。設定ミスでルート全体が保護されてしまうケースが想定される。

**事故リスク:** 中〜高。`exp` の型チェックは静的解析でしか気づけない。

---

### ペルソナ3: フロントエンド寄り経験者（JS/TS 歴4年・フルスタック転向中）

**クライアント側の JWT 保管:** `localStorage` に JWT を保存すると XSS で盗まれるリスクがある。`httpOnly Cookie` への保存が推奨されるが、その場合は CSRF 対策も必要になる。「なぜ Bearer トークンにしたのか」という設計判断を明文化することが重要。

**`Authorization: Bearer` ヘッダー:** fetch API で `headers: { Authorization: 'Bearer ' + token }` を付ける実装は馴染みがあるが、スペースが必要なこと（`'Bearer' + token` ではなく `'Bearer ' + token`）で間違えるケースがある。

**事故リスク:** 低〜中。クライアント側の JWT 保管が最大のリスク。

---

### ペルソナ4: バックエンド経験者（Laravel/Symfony 歴5年）

**Laravel Sanctum/Passport との比較:** Laravel のトークン認証では `sanctum` や `passport` がトークン発行・検証・ブラックリストを提供する。NENE2 の `LocalBearerTokenVerifier` は開発・テスト向けで本番ではライブラリ実装（`firebase/php-jwt`・`lcobucci/jwt`）が必要な点を把握できる。

**トークンリボーク:** JWT はステートレスなためリボークできない（有効期限まで使える）。ブラックリスト（Redis 等）との組み合わせが必要な場合は別途実装が必要。この設計上の制約を認識できる。

**`LocalBearerTokenVerifier` の命名:** "Local" が「開発用・本番非推奨」を示すシグナルになっており、適切な設計。

**事故リスク:** 低。ただしトークンリボークの欠如とシークレット管理に注意が必要。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・10年超）

**コードレビューポイント:**
1. `exp` クレームが int（Unix タイムスタンプ）か（文字列は有効期限チェックが機能しない）
2. シークレットが環境変数から読まれているか（ハードコード禁止）
3. `LocalBearerTokenVerifier` を本番環境で使っていないか
4. `alg` フィールドが HS256 固定で検証されているか（ライブラリ自前実装の場合）
5. `nene2.auth.claims` の取得で null チェックをしているか
6. トークンのレスポンスに `password_hash` 等の機密情報が含まれていないか
7. `Authorization` ヘッダーがログに出力されていないか
8. `excludedPaths` と `protectedPaths` の選択が意図通りか

**トークン有効期限の選択:** 1 時間は一般的だが、要件次第。リフレッシュトークンパターンとのトレードオフも考慮する。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `BearerTokenMiddleware` が WWW-Authenticate ヘッダーを自動付与（RFC 6750 準拠）— 良い設計
- `LocalBearerTokenVerifier` の命名が「本番非推奨」を明示している — 良い設計
- `alg: none` 拒否が実装されている — 安全なデフォルト

**設計上のギャップ:**
1. `nene2.auth.claims` 属性名が文字列定数として公開されていない — 文字列を直接書く必要がある
2. JWT 認証全体の howto が未作成
3. `excludedPaths` / `protectedPaths` / `protectedPathPrefixes` の選択ガイドが howto に必要

---

## Issues / PRs

- Issue: `docs/howto/jwt-authentication.md` — `LocalBearerTokenVerifier`・`BearerTokenMiddleware`・`excludedPaths` 設計・`nene2.auth.claims`・`exp` 型・`alg: none` 拒否・JWT シークレット環境変数
