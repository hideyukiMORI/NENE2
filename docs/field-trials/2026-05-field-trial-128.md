# Field Trial Report — FT128: Account Lockout (Brute-Force Protection)

**Date**: 2026-05-21
**Release**: v1.5.62
**App**: `lockoutlog` (`/home/xi/docker/NENE2-FT/lockoutlog/`)
**Tests**: 32/32 passed (27 SQLite + 5 MySQL)
**PHPStan**: level 8, 0 errors
**CS**: clean
**Special**: Cracker Attack Test (every 4th FT — FT120, FT124, FT128...) + MySQL Integration Test (every 5th FT — FT128, FT133...)

## Theme

Implement per-account brute-force protection: track failed login attempts per email, lock after threshold, auto-release after cooldown. The 12-attack cracker test probes the implementation for lockout bypass, user enumeration, DoS, injection, and timing vulnerabilities. The 5-test MySQL integration suite validates that the same logic works on MySQL with the `schema.mysql.sql` DDL.

## Core Design

### State Machine

```
unlocked (failed_count < 5) → +failure → unlocked (failed_count++)
unlocked (failed_count = 5) → +failure → locked (locked_until = now + 15min)
locked (now < locked_until) → any login → 423 Locked
locked (now >= locked_until) → next check → auto-released (isLocked returns false)
any state → successful login → unlocked (failed_count = 0, locked_until = NULL)
```

Lock expiry is computed lazily: `isLocked(now)` compares current time against `locked_until`. No background job needed.

### Lockout Check Before Password Verification

The lockout check happens **before** `password_verify()`. This is intentional: a locked account must not run Argon2id verification even with a correct password. Without this order, an attacker would know the password is correct if the response changes timing after lockout.

```php
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423;
}
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) { ... }
```

### Unknown Email Handling

`recordFailure()` is called only for **existing users**. For unknown emails, we return 401 immediately without writing to `account_states`. This prevents storage exhaustion: an attacker cannot fill the table by probing random email addresses.

The consequence: unknown-email attempts don't consume the failure budget. This is acceptable because an attacker trying to brute-force an account must know the email first.

### MySQL Compatibility

`Y-m-d H:i:s` datetime format works for both backends:
- SQLite: TEXT column, lexicographic string comparison is correct for ISO 8601
- MySQL: DATETIME column, string literal in WHERE clause is auto-converted

The MySQL schema uses `INT AUTO_INCREMENT PRIMARY KEY` and `UNIQUE KEY` instead of `INTEGER PRIMARY KEY AUTOINCREMENT` and `UNIQUE`.

## Cracker Attack Test Results

| Attack | Technique | Result |
|---|---|---|
| ATTACK-01 | Brute-force past threshold | Blocked — 423 after 5 failures |
| ATTACK-02 | Correct password submitted after lockout | Blocked — 423 enforced regardless |
| ATTACK-03 | Lockout DoS (attacker locks victim) | Expected behavior (documented trade-off) |
| ATTACK-04 | User enumeration via HTTP status | Prevented — same 401 for unknown and wrong password |
| ATTACK-05 | Oversized email (256+ chars) | Rejected — 422 before DB query |
| ATTACK-06 | SQL injection in email field | Prevented — parameterized queries, returns 401 |
| ATTACK-07 | Null byte injection in password | Prevented — `password_verify` treats null as literal, returns 401 |
| ATTACK-08 | Case-sensitivity email bypass | Returns 401 (not 200) — lockout on uppercase doesn't release lowercase |
| ATTACK-09 | Empty email | Rejected — 422 |
| ATTACK-10 | Whitespace-only email | Rejected — 422 (trim reduces to empty string) |
| ATTACK-11 | Password leaked in success response | No leak — only `id` and `email` returned |
| ATTACK-12 | Cross-account lockout isolation | Confirmed — locking alice doesn't affect bob |

### Notable Findings

**ATTACK-03 (Lockout DoS)**: This is an inherent design trade-off. Per-account lockout always allows an attacker who knows an email to lock out that user. The test confirms the behavior is intentional and documented. Mitigations (CAPTCHA, progressive delays, notification email) are noted in the howto but not implemented in this FT.

**ATTACK-08 (Case sensitivity)**: Email is trimmed but not lowercased. Failures accumulate on `ALICE@EXAMPLE.COM` separately from `alice@example.com`. This means an attacker could fail 5 times with the uppercase variant, which locks the uppercase account_state but NOT the lowercase one. The test only asserts that the correct-password attempt (with lowercase) does not return 200 — it returns 401 (wrong password, no match). This is acceptable: the real email `alice@example.com` is registered and can still log in. Case-sensitivity normalization (lowercasing at registration and login) would close this variant, but is a separate design decision.

## MySQL Integration Test Results

| Test | Result |
|---|---|
| testMysqlCreateAndLoginFlow | ✓ 201 create, 200 login |
| testMysqlLockoutAfterFiveFailures | ✓ 423 after 5 failures |
| testMysqlStatusEndpoint | ✓ correct failed_count returned |
| testMysqlSuccessfulLoginResetsCounter | ✓ failed_count = 0 after success |
| testMysqlUniqueEmailConstraint | ✓ duplicate email rejected (500 → caught by error handler → 4xx) |

The MySQL container is defined in `/home/xi/docker/NENE2-FT/docker-compose.yml` and uses the `nene2-ft_default` network.

## Files

```
database/schema.sql
database/schema.mysql.sql
src/Lockout/User.php
src/Lockout/AccountState.php
src/Lockout/LockoutRepository.php
src/Lockout/RouteRegistrar.php
tests/Lockout/LockoutTest.php      (15 SQLite tests)
tests/Lockout/AttackTest.php       (12 cracker attack tests)
tests/Lockout/MysqlLockoutTest.php (5 MySQL integration tests)
phpunit.xml
docs/howto/account-lockout.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

「ロックアウトってどうやって実装するの？」という疑問から入る。`account_states` テーブルに `failed_count` と `locked_until` を持つという発想は「なるほど」とすぐ理解できる。難しいのは「なぜロックアウトチェックをパスワード検証の前に行うのか」。「正しいパスワードでもロックされているなら弾く」ことは理解できても、「タイミング攻撃防止のため」という理由はすぐには気づけない。howto に明示してあるので後で理解できる。`isLocked(string $now)` に現在時刻を渡す設計は「なぜ `isLocked()` で内部で `date()` を呼ばないの？」という疑問につながる — テスタビリティのために外から渡すパターンを理解するには少し慣れが必要。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

「IPアドレスで制限するのではなく、メールアドレスで制限する」という設計判断に気づかない可能性がある。ThrottleMiddleware（FT107）との違いを理解していれば問題ないが、「レートリミットがあれば十分では？」と思ってしまう。また、「ロックアウト DoS リスクを知った上で per-account 設計を選んでいる」ことが howto に書かれているのは良い — 設計意図が残らないと将来の実装者が per-IP に変えてしまう恐れがある。未知メールには `account_state` を作らない設計は「ストレージ枯渇防止のため」という理由を説明されないと理解しにくい。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

`GET /auth/status/{email}` エンドポイントが便利 — フロントエンドでロックアウト残り時間カウントダウンを実装するのに `locked_until` がレスポンスにある。`is_locked` フラグとのセットは TypeScript 側で使いやすい。気になるのは `POST /auth/login` が 423 を返したとき、`locked_until` を含めていない点 — `locked_until` があれば `Retry-After` ヘッダーを計算せずとも残り時間を表示できる。`GET /auth/status/{email}` を別途呼ぶことでは対応できるが、ログイン API のエラーレスポンスに含めるとより DX が良い（RFC 9457 の `detail` フィールドに時間情報を入れるなど）。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

Laravel の `LoginThrottle` trait と概念は近いが、実装が透明で良い。気になるのは `findOrCreateAccountState()` の「SELECT → なければ INSERT → SELECT」という 2ステップ（トランザクションなし）。並行リクエストで同じ email に対して 2 件の INSERT が飛ぶ race condition がある — ただし `UNIQUE` 制約があれば 2 件目は一意制約エラーになる。その場合は `PDOException` がスローされて 500 になる。本番で同時並行が多い環境では `INSERT OR IGNORE` (SQLite) / `INSERT IGNORE` (MySQL) を使うか、`findOrCreate` にトランザクションを追加するのが安全。MySQL の `ON DUPLICATE KEY UPDATE` も選択肢。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

コードレビューで最初に確認するのは「ロックアウトチェックが password_verify より前か」（yes）と「unknown email に account_state を作らないか」（yes）。どちらも正しい。次に「`locked_until` のオートリリースは何でやっているか」を確認すると、バックグラウンドジョブなしで `isLocked(now)` の lazy check だと分かる — シンプルで良い。`recordFailure()` での race condition は Persona4 と同じ指摘。PHPStan level 8 が通っている点は良いが、`(string) (getenv('MYSQL_HOST') ?: '')` のキャストは `getenv()` が `false` を返す場合の対策として正しい。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — 3 ルートが RouteRegistrar に一覧。
- **薄いコントローラー**: ✓ — バリデーション → リポジトリ → レスポンス。
- **No magic**: ✓ — ロックアウトロジックが明示的な `isLocked()`・`recordFailure()`・`resetState()` メソッドに分離。
- **RFC 9457**: ✓ — 全エラーが ProblemDetailsResponseFactory 経由。
- **MySQL 対応**: ✓ — FT128 から MySQL テストを追加。`nene2-ft_default` ネットワーク経由で MySQL コンテナに接続。`schema.mysql.sql` を分離した設計は howto 用語で「explicit dual schema」。
- **設計懸念**: `findOrCreateAccountState()` のトランザクション境界なし（FT102・FT125・FT126・FT127 に続く同じ指摘）。FT ループで「複数 SQL → トランザクション」ガイドラインを策定するタイミングが近づいている。
