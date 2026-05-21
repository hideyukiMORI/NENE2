# Field Trial Report — FT124: User Invitation System

**Date**: 2026-05-21
**Release**: v1.5.58
**App**: `invitelog` (`/home/xi/docker/NENE2-FT/invitelog/`)
**Tests**: 26/26 passed (14 functional + 12 attack)
**PHPStan**: level 8, 0 errors
**CS**: clean
**Special**: Cracker Attack Test (every 4th FT — FT120, FT124…)

## Theme

Implement a token-based user invitation system: existing users can invite new users by email with a time-limited token. Key invariants: high-entropy tokens, expiry enforced at both read and write, owner-only cancel, no reuse of accepted/cancelled tokens.

## Core Design

### Token Generation

`bin2hex(random_bytes(32))` — 64 hex characters, 256 bits of entropy. The UNIQUE constraint on `invitations.token` prevents collision at the DB layer.

### Invitation Lifecycle

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

All status transitions are guarded: `isPending()` must return true before any accept or cancel. Once a token leaves `pending`, it is dead — returning 409 rather than allowing re-entry.

### Expiry Check Order

Expiry is checked **before** status in the accept handler. This matters: an expired invitation that is still `pending` should return 410 (Gone), not 409 (Conflict). Checking status first would return 409, which incorrectly implies the invitation was consumed rather than expired.

```php
if ($invite->isExpired($now)) {
    return 410;  // Gone
}
if (!$invite->isPending()) {
    return 409;  // Conflict — already consumed
}
```

### Owner Enforcement (Cancel)

Cancel requires `inviter_id` in the request body and returns 403 on mismatch. In a system without JWT/session auth, the inviter ID must be passed explicitly. In production, derive the actor ID from an authenticated token.

403 (not 404) is the correct response when the token exists but the caller is not the owner — 404 would imply the token doesn't exist, misleading the caller.

### Bug Found During Implementation

The initial `createInvitation()` INSERT had `token` and `status` positional parameters swapped:

```php
// WRONG — token column got 'pending', status column got the random token
[$inviterId, $email, 'pending', $token, $expiresAt, $now]

// CORRECT
[$inviterId, $email, $token, 'pending', $expiresAt, $now]
```

This caused all `findByTokenOrNull()` calls to return null (the token stored in DB was always `'pending'`). The bug was caught immediately by the first functional test. Lesson: column-order bugs in positional INSERT are easy to miss at authoring time but impossible to miss in tests.

## Cracker Attack Test Results

12 adversarial scenarios tested in `tests/Invitation/AttackTest.php`.

| # | Attack | Result | HTTP |
|---|---|---|---|
| 1 | Short/sequential token guessing | **DEFENDED** — token is 64 hex chars (256-bit) | — |
| 2 | Accept token twice (reuse) | **DEFENDED** — 409, no duplicate user created | 409 |
| 3 | Expired token acceptance | **DEFENDED** — 410, no user created | 410 |
| 4 | Cancelled token acceptance | **DEFENDED** — 409, no user created | 409 |
| 5 | Cross-user cancel (user B cancels user A's invite) | **DEFENDED** — 403 | 403 |
| 6 | `inviter_id=0` cancel bypass | **DEFENDED** — 403 | 403 |
| 7 | Information leakage (non-existent vs used token) | **DOCUMENTED** — 404 vs 409 differ, but no email PII in 409 body | 404/409 |
| 8 | Email enumeration via invite endpoint | **DOCUMENTED** — 409 reveals registration status; acceptable for invite-only trusted-inviter systems | 409 |
| 9 | SQL injection in email field | **DEFENDED** — PDO parameterised queries; table intact | 201 |
| 10 | Re-invite already-accepted user | **DEFENDED** — 409 (accepted user is now registered) | 409 |
| 11 | Oversized name in accept (10,000 chars) | **DEFENDED** — no 500, stored as-is | 201 |
| 12 | String `inviter_id` type coercion bypass | **DEFENDED** — handler coerces to int 0 → 403 | 403 |

### Attack 7 Detail — Non-existent vs Used Token

`GET /invitations/{token}` returns 404 for both non-existent and consumed tokens (since `findByTokenOrNull` returns null for non-existent). The attack scenario in AttackTest specifically tests the **accept** endpoint: non-existent → 404, already-used → 409. These differ, which is expected — a 409 tells the attacker the token once existed. The body does not expose the invited email, so no PII is leaked.

### Attack 8 Detail — Email Enumeration

`POST /users/{id}/invitations` returns 409 when the target email is already registered. This reveals whether an email is in the system. In invite-only systems with authenticated/trusted inviters this is acceptable — the inviter likely already knows the registrant. If this were a public signup flow, the response should be unified to 202 regardless of registration status.

### Attack 9 Detail — SQL Injection

The malicious string `' OR '1'='1'; DROP TABLE invitations; --` was stored as a literal email value (201 returned). PDO's parameterised queries prevented any SQL execution. The invitations table remained intact with 1 row.

## Files

```
database/schema.sql
src/Invitation/User.php
src/Invitation/Invitation.php
src/Invitation/InvitationRepository.php
src/Invitation/RouteRegistrar.php
tests/Invitation/InvitationTest.php     (14 functional tests)
tests/Invitation/AttackTest.php         (12 cracker attack tests)
docs/howto/user-invitation.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

招待トークンを `bin2hex(random_bytes(32))` で生成するのは分かりやすかった。一方で、INSERT の引数順序バグ（`token` と `status` が入れ替わっていた）は静的解析で検出できず、テストを書くまで気づけなかった。初心者には「named arguments で INSERT の列とバインドを対応させる方法はないの？」という疑問が生まれる場面だった。ステータスマシン（pending → accepted/cancelled）はシンプルで理解しやすい。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

`isExpired()` と `isPending()` のチェック順序（expiry first）が直感に反することがある。慣れた開発者でもステータス確認を先にやりたくなる。「期限切れなのに 409 が返る」バグを埋め込んでしまう可能性が高い。NENE2 では howto にチェック順を明示しているが、フレームワーク側でガードする仕組みがあるとより安全。cancel の 403 vs 404 の使い分けも、「存在するが禁止」と「存在しない」の違いを意識していないと間違える。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

招待フローの状態管理が明確で、クライアントサイドから見ると扱いやすい。202/201 の使い分け（invitation 作成は 201、export は 202）がやや不一致に見えるかもしれないが、招待を即座に DB に挿入しているため 201 は正しい。cancel が DELETE + ボディというのは TypeScript の fetch では `body` を明示的に渡す必要があり、やや非標準に感じる。`inviter_id` をボディで渡すパターンは JWT 時代には珍しく、TS クライアントで型を付けると「なぜ ID を送るの？」という疑問が出る。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

Laravel の `Invitation::make()` スタイルと比べると、NENE2 のリポジトリパターンは薄くて明快。ただし `findByToken()` (throws) と `findByTokenOrNull()` の 2 メソッドが並立しているのが少し冗長に感じる。cancel の所有権チェックを JWT なしで `inviter_id` ボディで行うのは「production 非推奨」と howto に明記されているが、ローカルでこのパターンが定着すると後から JWT に移行するリファクタが面倒になる。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

コードレビューで最初に指摘するのは `createInvitation()` の positional parameters — 6 引数もあると入れ替わりリスクが高い。named arguments か専用コマンドオブジェクトで渡すべき。email 列挙 (ATTACK 8) が「許容できる」と判断しているのは良いが、その判断をドキュメントに書いていることが重要。UNIQUE 制約が token カラムに張られているため DB レベルでの重複はないが、`createInvitation()` が例外をキャッチせずスローしている点は呼び出し側で問題になりうる（トークン衝突は現実にはあり得ないが、契約として null を返す/例外を投げる方針を統一すべき）。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — RouteRegistrar にルートテーブルが一目で分かる。
- **薄いコントローラー**: ✓ — バリデーション → リポジトリ呼び出し → レスポンスの流れが明確。
- **No magic**: ✓ — ステータス遷移は明示的な `isPending()` ガードで制御。
- **RFC 9457 Problem Details**: ✓ — 全エラーが `ProblemDetailsResponseFactory` 経由。
- **DI**: ✓ — `readonly` コンストラクタインジェクション。
- **懸念点**: `inviter_id` をボディで渡すパターンは認証なし運用の妥協点。ポリシーとしては明記が必要（howto で対応済み）。INSERT の引数順バグはポリシーレベルで「named parameters 推奨」を追記するきっかけにできる。
