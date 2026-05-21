# Field Trial Report — FT126: Password Reset Flow

**Date**: 2026-05-21
**Release**: v1.5.60
**App**: `resetlog` (`/home/xi/docker/NENE2-FT/resetlog/`)
**Tests**: 15/15 passed
**PHPStan**: level 8, 0 errors
**CS**: clean
**Special**: Vulnerability Assessment (every 3rd FT — FT114, FT117, FT120, FT123, FT126…)

## Theme

Implement a token-based password reset flow: request reset (with user enumeration prevention) → verify token → complete reset. Token stored as SHA-256 hash; raw token only returned to user; one-time use enforced; old tokens invalidated on new request.

## Core Design

### Token Storage

Raw token: `bin2hex(random_bytes(32))` — 256-bit entropy, returned to the user (typically via email).
DB: `token_hash = hash('sha256', $rawToken)` — only the hash is stored.

If the DB is breached, the attacker gets SHA-256 hashes of random 256-bit values. Reversing these is computationally infeasible. This is the same principle as password hashing, applied to reset tokens.

### User Enumeration Prevention

`POST /password-reset` always returns 202, regardless of whether the email is registered. The response body differs only in whether `token` is present — this is acceptable in test/API-direct contexts. In a real email-flow system, the token would be in the email, not the response, making the two cases indistinguishable to the caller.

### Expiry and One-Time Use

Both `GET /password-reset/{token}` (status check) and `POST /password-reset/{token}` (complete) enforce the same guards in the same order:
1. Not found → 404
2. Expired → 410
3. Already used → 409

The order matters: an expired-and-used token returns 410 (expired), not 409 (used). Expiry supersedes usage state.

### Old Token Invalidation

When a new reset is requested, all previous unused tokens for that user are invalidated by setting `used_at = $now`. This prevents the scenario where:
- User requests reset, loses email
- User requests reset again
- Attacker finds the old email (e.g., in a compromised inbox) and uses the first token

## Vulnerability Assessment

### VULN-A: `user_id` exposed in `GET /password-reset/{token}` response — FIXED

**Initial state**: `PasswordReset::toArray()` returned `user_id` alongside other fields.

**Risk**: The reset token grants access to a specific user's account. Exposing `user_id` in the response links the token to an internal account identifier. An attacker who intercepts an in-flight reset URL (e.g., via referrer header, browser history) could extract the `user_id` and use it in other endpoints.

**Fix**: Removed `user_id` and `used_at` from `toArray()`. The response now returns only `id`, `expires_at`, and `created_at`.

```php
// BEFORE — leaked user_id
return ['id' => $this->id, 'user_id' => $this->userId, ...];

// AFTER — no internal identifiers
return ['id' => $this->id, 'expires_at' => $this->expiresAt, 'created_at' => $this->createdAt];
```

**Test**: `testGetValidResetReturns200` verifies no leakage via the 200 response shape.

### VULN-B: Token stored as plaintext — NOT PRESENT

Token is stored as `hash('sha256', $rawToken)` in `token_hash`. The raw token never touches the DB.

### VULN-C: User enumeration via `POST /password-reset` — NOT PRESENT

Always returns 202. Verified by `testRequestResetForUnknownEmailReturns202WithoutToken`.

### VULN-D: Expiry bypass — NOT PRESENT

`isExpired()` compares datetime strings lexicographically. Expiry is enforced at both GET and POST. Test: `testCompleteResetExpiredTokenReturns410`.

### VULN-E: Token reuse — NOT PRESENT

`markUsed()` sets `used_at` on completion. `isUsed()` guards both GET and POST. Test: `testCompleteResetMarksTokenUsed`.

### VULN-F: Old tokens not invalidated — NOT PRESENT

`createReset()` calls `UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL` before inserting the new token. Test: `testRequestingNewResetInvalidatesPreviousToken`.

### VULN-G: `token_hash` exposed in response — NOT PRESENT

`toArray()` does not include `token_hash`. The hash field is internal-only.

### Summary

| ID | Finding | Severity | Status |
|---|---|---|---|
| VULN-A | `user_id` exposed in GET /password-reset/{token} response | Low | **FIXED** |
| VULN-B | Token stored in plaintext | High | Not present |
| VULN-C | User enumeration via reset endpoint | Medium | Not present |
| VULN-D | Expiry bypass | High | Not present |
| VULN-E | Token reuse | High | Not present |
| VULN-F | Old tokens not invalidated | Medium | Not present |
| VULN-G | token_hash exposed in response | Medium | Not present |

## Files

```
database/schema.sql
src/Reset/User.php
src/Reset/PasswordReset.php
src/Reset/ResetRepository.php
src/Reset/RouteRegistrar.php
tests/Reset/PasswordResetTest.php    (15 tests)
docs/howto/password-reset.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

「なぜ生トークンを DB に保存しないのか？」が最初の疑問。SHA-256 ハッシュ保存の理由（DB 漏洩対策）を理解するには、パスワードハッシュ（FT109）と同じ考え方を適用できると気づく必要がある。howto でその比較が書いてあると助かる。`POST /password-reset` が未登録メールでも 202 を返す設計は、最初は「なぜエラーを返さないの？」と感じる。ユーザー列挙攻撃の説明があれば納得できる。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

`isExpired()` チェックを `isUsed()` の前に置く順序を意識しない可能性が高い。「期限切れでも使用済みでも 409 で返せばいいのでは？」と考えて、410 と 409 を区別しない実装にしてしまうことがある。また、旧トークン無効化を忘れやすい — 「新しいトークンが有効になれば古いトークンは自動的に使えなくなると思っていた」という誤解が生じる。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

リセットフロー全体が GET → POST の 2 ステップになっていて、UI フロー（トークン確認画面 → パスワード入力画面）に自然に対応できる。202 で `token` が返ってくる設計はテスト用で便利だが、本番では email 経由になるため、TypeScript クライアントで `token` フィールドを optional にしておく必要がある。`expires_at` がレスポンスに含まれているので、クライアント側でカウントダウンタイマーを実装しやすい。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

Laravel の `Password::sendResetLink()` と比べると実装が透明で良い。ただし `createReset()` がトランザクションなしで「旧トークン無効化 → 新トークン挿入」の 2 ステップを行っている点が気になる。並列リクエストで 2 つの新トークンが同時に挿入される可能性は低いが、`UNIQUE (user_id, used_at IS NULL)` のような制約がないとロジックレベルで複数アクティブトークンが生まれる余地がある（現実的リスクは低い）。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

コードレビューでまず確認するのは「token_hash カラムに UNIQUE 制約があるか」（ある）と「old token invalidation があるか」（ある）。VULN-A の `user_id` 露出は典型的な「つい入れてしまう」フィールドなので、`toArray()` のレビュー時に必ずチェックする。`POST /password-reset/{token}` でパスワードバリデーション（422）がトークン検索より先に実行されていることは問題ない — short-circuit で DB 負荷を下げる意図があるなら明示的なコメントがあると良い。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — 4 ルートが RouteRegistrar に一覧。
- **薄いコントローラー**: ✓ — バリデーション → リポジトリ → レスポンス。
- **No magic**: ✓ — SHA-256 ハッシュ生成・比較がコード上で可視。
- **RFC 9457**: ✓ — 全エラーが ProblemDetailsResponseFactory 経由。
- **設計懸念**: `createReset()` のトランザクション境界なし。脆弱性リスクは低いが、ポリシー上は「複数 SQL → トランザクション境界を明示」に反する。FT125 の setPostTags() と同じ指摘 — FT ループでトランザクション境界の扱いを統一するガイドラインを追記する候補。
