# Field Trial 94 — JWT Bearer Token Authentication Edge Cases

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/authlog/`
**NENE2 version:** 1.5.27
**Theme:** Security — JWT Bearer token edge cases: expired tokens, invalid signatures, malformed tokens, algorithm substitution (`alg:none`), `nbf` not-before, wrong scheme, tampered payload, IDOR cross-user

---

## What was built

A private diary API fully protected by `BearerTokenMiddleware` + `LocalBearerTokenVerifier`. All endpoints require a valid HS256 JWT in the `Authorization: Bearer <token>` header. The `sub` claim determines ownership; entries are isolated per user at the SQL level (`WHERE owner_id = ?`).

### Endpoints

| Method | Path | Auth |
|--------|------|------|
| POST | `/entries` | Required — creates entry for token's `sub` |
| GET | `/entries` | Required — lists only entries owned by token's `sub` |
| GET | `/entries/{id}` | Required — 404 if entry belongs to different user |

---

## Frictions encountered

### 1. `RuntimeApplicationFactory` parameter name: `authMiddleware` not `middlewares` (中)

**Symptom:** `Error: Unknown named parameter $middlewares` — 18 test errors.

When wiring `BearerTokenMiddleware` into the app, the natural guess is `middlewares: [...]`. The actual named parameter is `authMiddleware`, and it accepts either a single `MiddlewareInterface` or a `list<MiddlewareInterface>`:

```php
// Wrong (natural guess):
new RuntimeApplicationFactory(..., middlewares: [$bearer]);

// Correct:
new RuntimeApplicationFactory(..., authMiddleware: $bearer);
```

The parameter name `authMiddleware` is not immediately discoverable without reading the source. Developers who expect a generic `middlewares` array will hit a runtime error.

**DX観点:** 初心者は `middlewares` を試して「なぜ動かないのか」と迷う。パラメータ名がユースケース（auth用）に特化しているため、複数のカスタムミドルウェアを汎用的に追加したい場合の経路が不明確。

**Suggested fix:** PHPDoc にパラメータ名の意図（auth専用）を明記するか、サンプルを howto に追加する。

---

### 2. Problem Details `type` はショートスラッグではなく完全 URI (低)

**Symptom:** テストで `$data['type'] === 'unauthorized'` が失敗。実際の値は `https://nene2.dev/problems/unauthorized`。

Problem Details RFC 9457 準拠の正しい挙動だが、テストを書く開発者がショートスラッグを期待して `assertEquals('unauthorized', $data['type'])` と書くと失敗する。

**Fix:** `assertStringContainsString('unauthorized', $data['type'])` を使うか、定数を用意する。

---

### 3. `LocalBearerTokenVerifier` — `exp` クレームなしのトークンが通過する (低)

**Symptom:** `exp` を持たないトークンが認証を通過する（期限なしトークン）。

```php
$token = $verifier->issue(['sub' => 'user-1']); // no exp
// → 200 OK
```

NENE2 の実装は `exp` がある場合のみ期限検証をする。`exp` なしは期限切れにならない。これはセキュリティ設計上の選択だが、初心者は `exp` 必須だと思い込みやすい。

**ドキュメントへの追記推奨:** `LocalBearerTokenVerifier::issue()` を呼ぶときは `exp` クレームを常に含めることを推奨する旨を howto に記載する。

---

### 4. `alg:none` 攻撃 — NENE2 は正しく防ぐ (摩擦なし・確認事項)

`{"alg":"none","typ":"JWT"}` ヘッダーで署名を空にした JWT を送ると、`LocalBearerTokenVerifier` は `'Token algorithm must be HS256.'` で 401 を返す。正しく防御されている。

---

### 5. ペイロード改ざん — NENE2 は正しく検出 (摩擦なし・確認事項)

`header.tampered_payload.original_sig` パターン（署名を変えずにペイロードを書き換え）は署名検証で 401 になる。`hash_equals` による定数時間比較も実装済み。

---

## Test results

18 tests, 26 assertions — all pass.

Tested scenarios:
- 有効トークンで正常アクセス
- 複数ユーザーのエントリ分離（`sub` による所有者フィルタ）
- Missing auth header → 401 + `WWW-Authenticate` ヘッダー確認
- Basic 認証スキーム → 401
- Bearer プレフィックスなし → 401
- 期限切れトークン（`exp` 過去） → 401
- `nbf` 未来のトークン → 401
- 別シークレットの署名 → 401
- ペイロード改ざん（署名不一致） → 401
- 空トークン → 401
- ランダム文字列トークン → 401
- 2パーツトークン → 401
- 4パーツトークン → 401
- 非Base64ペイロード → 401
- `alg:none` → 401
- IDOR（他ユーザーのエントリ） → 404
- `exp` なしトークン → 200（期限なし動作の確認）

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

`BearerTokenMiddleware` + `LocalBearerTokenVerifier` の組み合わせ自体はシンプルで理解しやすい。ただし `RuntimeApplicationFactory` のパラメータ名（`authMiddleware`）が直感に反する（`middlewares` を期待する）。IDE 補完があれば気づけるが、なければ実行時エラーで迷う。

エラーレスポンスに `WWW-Authenticate` ヘッダーが自動付与される点は初心者に優しい（RFC 準拠を意識しなくてよい）。

### 使ってみた印象

セキュリティ関連のコードが驚くほど短く書ける。`authMiddleware: $bearer` の一行でルート全体を保護でき、`$request->getAttribute('nene2.auth.claims')` でクレームを取れるのは快適。

`LocalBearerTokenVerifier` が `TokenIssuerInterface` も実装しているため、テストで `issue()` を呼ぶだけでトークンが作れるのは非常に便利。

### 楽しいか・気持ちいいか・快適か

テストでトークン攻撃シナリオを次々と検証していく体験は面白い。「`alg:none` で弾かれるか？」「ペイロードを改ざんして弾かれるか？」が素直な assert で確認できる。

セキュリティを「試せる」設計になっていることに好感。

### 簡単か

ハッピーパスは簡単。エッジケース（`alg:none`、`nbf`）は知識がないと思いつかないが、それは NENE2 の問題ではなく JWT の仕様の問題。

`authMiddleware` パラメータ名さえわかれば設定は1行。

### また使いたいか

はい。テストしやすく、セキュリティが正しく実装されていて安心感がある。

### 初心者に勧めたいか

はい、ただし「`exp` クレームは必ず付ける」「`authMiddleware` というパラメータ名を使う」の2点をドキュメントに明記すれば推薦度が上がる。

---

## Issues / PRs

- Issue: `RuntimeApplicationFactory` の `authMiddleware` パラメータ名を howto で案内する
- Issue: JWT トークン発行ガイド（`exp` 必須の推奨、`LocalBearerTokenVerifier` の使い方）
