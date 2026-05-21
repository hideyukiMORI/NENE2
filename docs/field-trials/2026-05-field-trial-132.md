# Field Trial 132 — User Profile Management

**Date**: 2026-05-21
**Version**: v1.5.66
**Project**: `profilelog`
**Theme**: User profile CRUD with ownership enforcement
**Special**: Cracker Attack Test (12 attacks) + Vulnerability Assessment

## Summary

Implemented a user profile management API with email-based user registration, profile creation/update, and ownership enforcement via `X-User-Id` header. 32 tests total (20 normal + 12 attack), all passing.

Vulnerability assessment found 1 issue (VULN-A), fixed before release.

## What Was Built

- `POST /users` — register user (email validated, 409 on duplicate)
- `POST /users/{userId}/profile` — create profile (409 if already exists)
- `GET /users/{userId}/profile` — get profile
- `PUT /users/{userId}/profile` — update profile (ownership via `X-User-Id`)

Key design decisions:
- Avatar URL validated: `https://` only (blocks `javascript:`, `data:`, `http://`, `file://`)
- Field limits: bio 500 chars, display_name 100 chars, avatar_url 2048 chars
- `mb_strlen()` for multibyte correctness
- `UNIQUE (user_id)` on profiles enforces one-profile-per-user at DB level
- `DatabaseConstraintException` caught for duplicate email → 409 (not 500)
- Ownership check: `X-User-Id` header resolves to 0 when missing/non-numeric

## Test Results

| Suite | Tests | Result |
|---|---|---|
| ProfileTest (SQLite) | 20/20 | PASS |
| AttackTest (SQLite) | 12/12 | PASS |
| **Total** | **32/32** | **PASS** |

```
OK (32 tests, 62 assertions)
```

## Vulnerability Assessment

### VULN-A: Duplicate email causes unhandled DatabaseConstraintException → 500

**Severity**: Medium  
**Location**: `RouteRegistrar::createUser()`

**Description**: The `users` table has a `UNIQUE` constraint on `email`. When a duplicate email was submitted, `PdoDatabaseQueryExecutor` threw `DatabaseConstraintException` which was not caught by the handler. This caused a 500 response that could expose internal error details.

**Fix applied**:
```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

**Verification**: `testCreateUserDuplicateEmailReturns409()` confirms 409 is returned.

### Design Limitation: X-User-Id header is unauthenticated

**Severity**: By design (FT scope)  
**Description**: The `X-User-Id` header used for ownership verification has no cryptographic backing. A client can set it to any user ID. This is documented as a known limitation — in production, this would be replaced by a JWT claim (see FT110, FT111).

**No fix**: Within FT scope, the ownership logic is correct. The missing piece is JWT authentication as the source of the actor ID.

## Cracker Attack Test Results

| # | Attack | Input | Expected | Result |
|---|---|---|---|---|
| ATTACK-01 | IDOR: update without auth | `PUT` with no `X-User-Id` | 403 | ✅ PASS |
| ATTACK-02 | IDOR: forge wrong user ID | `X-User-Id: <attacker_id>` for victim's profile | 403 | ✅ PASS |
| ATTACK-03 | XSS: `javascript:` URI in avatar | `"avatar_url": "javascript:alert(1)"` | 422 | ✅ PASS |
| ATTACK-04 | Data URI injection | `"avatar_url": "data:text/html,<script>..."` | 422 | ✅ PASS |
| ATTACK-05 | HTTP (non-HTTPS) avatar | `"avatar_url": "http://internal/secret"` | 422 | ✅ PASS |
| ATTACK-06 | DoS: 100KB bio | `"bio": "A" × 100,000` | 422 | ✅ PASS |
| ATTACK-07 | SQL injection in display_name | `"'; DROP TABLE profiles; --"` | 201 (stored safely) | ✅ PASS |
| ATTACK-08 | XSS payload in bio | `<script>...</script>` | 201 (API stores text; client renders safely) | ✅ PASS |
| ATTACK-09 | Negative user ID | `/users/-1/profile` | 404 | ✅ PASS |
| ATTACK-10 | Zero user ID | `/users/0/profile` | 404 | ✅ PASS |
| ATTACK-11 | Huge integer user ID | `/users/999999999999999999/profile` | 404/400 | ✅ PASS |
| ATTACK-12 | Non-numeric X-User-Id | `X-User-Id: admin` | 403 | ✅ PASS |

**全 12 攻撃耐久**

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 歴 6 ヶ月）

**印象**: メールの UNIQUE 制約違反が 500 になる落とし穴は最初気づかなかった。`DatabaseConstraintException` を catch するパターンを howto で見て初めて理解できた。`isValidAvatarUrl()` の `str_starts_with('https://')` チェックが「なぜ FILTER_VALIDATE_URL だけではダメか」の理由（`javascript:` が valid URL として通る）を知れて勉強になった。

**摩擦点**: `X-User-Id` ヘッダーが「本番では JWT にすべき」という前提が分かりにくい。コメントや howto の注記で補完して良かった。

### Persona 2 — Laravel 経験者

**印象**: Eloquent の `firstOrCreate` のような高レベル抽象がないため、「プロフィールが存在するか確認してから INSERT」というロジックを明示的に書く必要がある。冗長に見えるが、レース条件の挙動が予測しやすい。`DatabaseConstraintException` のキャッチは Laravel の `QueryException` キャッチと同等のパターン。

**摩擦点**: Eloquent なら `$user->profile()->updateOrCreate(...)` で済む部分が 3 ハンドラに分かれる。設計の意図は明確なので許容範囲。

### Persona 3 — フロントエンドエンジニア（React 開発者）

**印象**: 409 で「既に存在する」を返してくれるのでフォーム側で分岐しやすい。`GET /users/{userId}/profile` が 404 を返すとき「ユーザーが存在しない」か「プロフィールが未作成」か区別できないが、エラーメッセージに `'profile not found'` vs `'user not found'` が入っているので判別できる。

**摩擦点**: プロフィール画像の URL を `https://` に限定するのはフロントでも同様のバリデーションをしているが、両方に制約があることで UX が二重チェックになる。バランスは適切だと思う。

### Persona 4 — セキュリティエンジニア

**印象**: `javascript:` と `data:` の URI スキームを `str_starts_with('https://')` で防いでいるのは正しいアプローチ。FILTER_VALIDATE_URL だけでは `javascript:alert(1)` が通るため、スキーム固定チェックが必須。SQL インジェクションはパラメータ化クエリで防いでいる（ATTACK-07 で確認）。`DatabaseConstraintException` を catch して 409 を返すことでスタックトレースが漏れない。

**残留リスク**: `X-User-Id` 認証なし。本番投入前に JWT 認証（FT110）との統合が必要。bio の XSS（ATTACK-08）はサーバー側では JSON テキストとして格納しており、フロントの責任とする設計は適切だが、API ドキュメントに明記すべき。

### Persona 5 — DevOps / SRE エンジニア

**印象**: `UNIQUE (user_id)` インデックスが自動で作成されるためプロフィール取得が高速。メールの UNIQUE インデックスも同様。現在は SQLite だが、MySQL 移行時も同じスキーマが動作する（`AUTOINCREMENT` → `AUTO_INCREMENT` の差分のみ）。

**摩擦点**: プロフィールの `updated_at` はアプリ側で `date('Y-m-d H:i:s')` を使っているため、サーバー時刻依存。高トラフィックや複数サーバー環境では DB 側の `NOW()` を使う方が安全。

### Persona 6 — テックリード（コードレビュー担当）

**印象**: `extractProfileFields()` で共通バリデーションを切り出しているため、create と update で同じ制約が保証されている。`isValidAvatarUrl()` が private で RouteRegistrar に閉じているのは適切（ビジネスルール）。`DatabaseConstraintException` catch は早期リリースで抜けやすい穴で、今回の脆弱性診断で発見・修正されたのは良いサイクル。PHPStan level 8 / PHP-CS-Fixer 通過。

**改善提案**: `resolveActorId()` を抽象化して将来の JWT 認証切り替えを容易にすることができる。現時点では 1 メソッドで十分。

## Howto Coverage

- `docs/howto/user-profile-management.md` 追加
- `DatabaseConstraintException` catch パターン、avatar_url https 強制、mb_strlen、X-User-Id 所有権チェックを文書化
