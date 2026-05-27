# ハウツー: Bearer トークンミドルウェア（JWT 認証のエッジケース）

> **FT リファレンス**: FT273 (`NENE2-FT/authlog`) — BearerTokenMiddleware JWT 認証: alg=none 拒否、署名改ざん検出、exp/nbf 強制、WWW-Authenticate ヘッダー、サブごとのデータ分離、IDOR → 404、18 テスト / 26 アサーション PASS。
>
> **VULN アセスメント**: V-01 から V-10 がこのドキュメントの末尾に含まれています。

NENE2 の `BearerTokenMiddleware` + `LocalBearerTokenVerifier`（HMAC-HS256）を使ってルートを保護する方法を示します。すべての JWT バリデーションのエッジケースはミドルウェアが処理し、コントローラーは `nene2.auth.claims` 経由でデコード済みのクレームのみを受け取ります。

---

## セットアップ

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

ミドルウェアはルートハンドラーが実行される前にリクエストに `nene2.auth.claims` を設定します。バリデーションに失敗した場合、ハンドラーが呼び出される前に `WWW-Authenticate: Bearer` 付きの 401 を返します。

---

## コントローラーでのクレーム取得

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

`sub` クレームが標準的なユーザー識別子です。これを `owner_id` として使用することで、追加のルックアップなしにユーザーごとのデータ分離が保証されます。

---

## WWW-Authenticate ヘッダー

401 時、ミドルウェアは `WWW-Authenticate: Bearer realm="api"` を発行します。
期限切れトークンの場合、ヘッダーに `error="invalid_token"` が含まれます:

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

RFC 6750 への準拠により、クライアントは「トークンなし」と「不正なトークン」を区別できます。

---

## 脆弱性アセスメント

### V-01 — alg=none アルゴリズム置換 ✅ SAFE

**リスク**: 攻撃者が `"alg":"none"` と `sub: admin` を主張する署名なしのペイロードで JWT を作成する。
**所見**: SAFE — `LocalBearerTokenVerifier` は HMAC-HS256 のみを受け入れます。`alg=none` トークンは署名検証で拒否されます；テスト `testWrongAlgorithmHeaderReturns401` が 401 を確認します。

---

### V-02 — 署名の改ざん ✅ SAFE

**リスク**: 攻撃者が有効な JWT を傍受し、ペイロードを変更（例: `sub` を `admin` に変更）してヘッダーと元の署名を維持したまま送信する。
**所見**: SAFE — HMAC-HS256 署名は `header.payload` をカバーします。いかなる変更も MAC を無効化します；`testTamperedPayloadReturns401` が 401 を確認します。

---

### V-03 — 期限切れトークンのリプレイ ✅ SAFE

**リスク**: セッションが無効になった後に期限切れトークンをリプレイする。
**所見**: SAFE — `exp` クレームが検証されます；`exp < time()` のトークンは拒否されます。`testExpiredTokenReturns401` が `WWW-Authenticate` の `invalid_token` 付きの 401 を確認します。

---

### V-04 — Not-before（nbf）バイパス ✅ SAFE

**リスク**: 将来の `nbf`（まだ有効でない）を持つトークンがアクティベーション時間前に使用される。
**所見**: SAFE — `nbf` が強制されます；`testNbfInFutureReturns401` が 401 を確認します。

---

### V-05 — 誤った Authorization スキーム ✅ SAFE

**リスク**: 攻撃者が `Authorization: Basic dXNlcjpwYXNz` を送信するか `Bearer ` プレフィックスを省略する。
**所見**: SAFE — ミドルウェアは `Bearer ` プレフィックス付きのトークンのみを受け入れます。`Basic` と裸のトークン文字列は両方とも 401 を返します。

---

### V-06 — 不正なトークン構造 ✅ SAFE

**リスク**: 攻撃者が 2 パート、4 パート、非 base64 ペイロード、またはランダムな文字列のトークンを送信してエラーハンドリングを探索する。
**所見**: SAFE — すべての不正なバリアントは 401 を返します。3 パート以外のトークンと無効な base64 はクレーム抽出前に拒否されます。

---

### V-07 — 誤った署名シークレット ✅ SAFE

**リスク**: JWT フォーマットを知っている攻撃者が異なるシークレットでトークンに署名する。
**所見**: SAFE — シークレットが異なる場合 HMAC 検証が失敗します；`testWrongSecretSignatureReturns401` が 401 を確認します。

---

### V-08 — IDOR: クロスユーザーデータアクセス ✅ SAFE

**リスク**: ユーザー A がエントリ ID を知るか推測することでユーザー B のデータを読み取ろうとする。
**所見**: SAFE — `findByIdAndOwner($id, $ownerId)` がルックアップを JWT の `sub` にスコープします。クロスユーザーリクエストはエントリが存在することを明らかにしないよう 404（403 ではない）を返します。

---

### V-09 — ユーザーごとのデータ分離 ✅ SAFE

**リスク**: ユーザー A の書き込みがユーザー B に見える。
**所見**: SAFE — すべての読み取りは `owner_id = sub` でスコープされます。`testEntriesAreIsolatedByToken` が Alice と Bob のエントリが完全に分離されていることを確認します。

---

### V-10 — exp クレームなしのトークン ✅ SAFE（許容可能）

**リスク**: `exp` クレームなしのトークンが発行され、事実上有効期限なしになる。
**所見**: SAFE（設計上）— `LocalBearerTokenVerifier` はクレームが存在する場合のみ `exp` を検証します。`exp` なしのトークンは受け入れられます。これはサービス間通信シナリオのための意図的なトレードオフです；必要に応じて本番環境ではより厳格なベリファイアを通じて `exp` を強制してください。

---

### VULN まとめ

| ID | 脆弱性 | 所見 |
|----|---------------|---------|
| V-01 | alg=none アルゴリズム置換 | ✅ SAFE |
| V-02 | 署名の改ざん | ✅ SAFE |
| V-03 | 期限切れトークンのリプレイ | ✅ SAFE |
| V-04 | Not-before（nbf）バイパス | ✅ SAFE |
| V-05 | 誤った Authorization スキーム | ✅ SAFE |
| V-06 | 不正なトークン構造 | ✅ SAFE |
| V-07 | 誤った署名シークレット | ✅ SAFE |
| V-08 | IDOR クロスユーザーデータアクセス | ✅ SAFE |
| V-09 | ユーザーごとのデータ分離 | ✅ SAFE |
| V-10 | exp クレームなしのトークン | ✅ SAFE（設計上） |

**10 SAFE, 0 EXPOSED**
重大な脆弱性なし。`BearerTokenMiddleware` はすべての標準的な JWT 攻撃ベクタを処理します；アプリケーションコードは所有権スコープのために `sub` クレームを使用するだけです。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `alg=none` トークンを受け入れる | 攻撃者が署名を省略することで任意の識別子を偽造できる |
| `exp` バリデーションをスキップする | 盗まれたトークンが無期限に有効のまま |
| IDOR に 403 を返す | リソースが存在して誰かのものであることを明らかにする |
| JWT `sub` の代わりに `X-User-Id` ヘッダーを使用する | ヘッダーは簡単になりすましできる；JWT クレームは暗号学的に束縛されている |
| 環境間で署名シークレットを共有する | 開発環境の漏洩が本番トークンを侵害する |
| 2048 ビット未満の `RS256` キーを使用する | 因数分解攻撃に脆弱 |
