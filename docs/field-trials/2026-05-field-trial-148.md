# Field Trial 148 — OTP 認証システム（OTP Authentication）

**Date**: 2026-05-21  
**App**: `otplog`  
**Path**: `/home/xi/docker/NENE2-FT/otplog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.82  
**Special**: MySQL 統合テスト（5テスト）+ クラッカー攻撃試験（AttackTest.php, 12テスト）

---

## What was built

ワンタイムパスワード（OTP）認証システムを実装した。
メールアドレスに 6 桁コードを送り、検証後にセッショントークンを発行する。

| Endpoint | 説明 |
|---|---|
| `POST /otp/request` | OTP コードを生成・発行（常に 202、列挙防止） |
| `POST /otp/verify` | OTP を検証してセッション発行 |
| `GET /otp/session` | セッション有効性確認 |
| `DELETE /otp/session` | ログアウト（セッション無効化） |

---

## Architecture decisions

### コード生成とハッシュ保存

`random_int(0, 999999)` で 6 桁コードを生成し、`str_pad` でゼロ埋めする。
コードは SHA-256 ハッシュで DB に保存し、生コードは保存しない。
検証時は `hash('sha256', $input)` と `hash_equals()` でタイミング攻撃を防ぐ。

### ユーザー列挙防止

`POST /otp/request` は存在しないメールアドレスにも 202 を返す（`findOrCreateUser` パターン）。
存在確認と作成を 1 アトミック操作に統合することで、ステータスコードによる列挙を防ぐ。

### 試行回数制限（3回でロックアウト）

失敗ごとに `attempt_count` をインクリメントし、`MAX_ATTEMPTS`（3）到達で `locked_until` を 10 分後に設定。
ロックアウトチェックを検証ロジックの最優先として行うことで、ロック中に有効なコードが
送られても試行を完全に拒否する。

### 最新 OTP のみ有効

`ORDER BY id DESC LIMIT 1` で最新の OTP レコードのみを検証対象とする。
複数回リクエストしても古いコードは無効になるため、古いコードの横流しができない。

### セッショントークン

`bin2hex(random_bytes(32))` = 256-bit ランダム → SHA-256 ハッシュで DB 保存。
Bearer トークンで認証し、`DELETE /otp/session` でセッションを無効化（`revoked_at` 設定）。

### .php-cs-fixer の setRiskyAllowed(true)

`declare_strict_types` は CS Fixer のリスキーフィクサーに分類されるため、
`.php-cs-fixer.php` に `->setRiskyAllowed(true)` が必要。これなしだと exit code 16 で失敗する。

---

## Attack test results (AttackTest.php)

12 件のクラッカー攻撃シナリオを検証した。

| ID | 攻撃シナリオ | 結果 |
|---|---|---|
| ATK-01 | ブルートフォース（3回でロックアウト） | Pass |
| ATK-02 | 使用済み OTP のリプレイ攻撃 | Pass |
| ATK-03 | ユーザー列挙（/otp/request は常に 202） | Pass |
| ATK-04 | 存在しないユーザーへの verify（401、500 でない） | Pass |
| ATK-05 | メールフィールドへの SQL インジェクション | Pass |
| ATK-06 | 5 桁コード（短すぎる）| Pass |
| ATK-07 | 7 桁コード（長すぎる）| Pass |
| ATK-08 | ログアウト後のセッショントークン再利用 | Pass |
| ATK-09 | ランダムトークン推測 | Pass |
| ATK-10 | 空の Bearer トークン | Pass |
| ATK-11 | アルファベットコード（非数字）| Pass |
| ATK-12 | 古い OTP は新規リクエスト後に無効 | Pass |

---

## MySQL integration test results (MysqlOtpTest.php)

| テスト | 内容 | 結果 |
|---|---|---|
| testMysql_requestAndVerify | リクエスト→検証フルフロー | Pass |
| testMysql_sessionValidationAndRevocation | セッション確認→ログアウト | Pass |
| testMysql_lockoutAfterFailedAttempts | 3回失敗→429 | Pass |
| testMysql_sameEmailNotDuplicated | 同メール重複なし | Pass |
| testMysql_invalidCodeReturns401 | 誤コード→401 | Pass |

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `OtpTest.php` (SQLite) | 18 | Pass |
| `AttackTest.php` (SQLite) | 12 | Pass |
| `MysqlOtpTest.php` (MySQL) | 5 | Pass |
| **Total** | **35** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「OTP は SMS や銀行アプリで使ったことがある身近な機能なのでイメージしやすかった。
`random_int(0, 999999)` で 6 桁コードを作って `str_pad` でゼロ埋めする、という組み合わせが
シンプルで気に入った。`hash_equals()` を使う理由が最初は謎だったが、
『=== と比較時間が変わることでコードの桁数を推測される』タイミング攻撃の説明で理解できた。
ロックアウトが `attempt_count` カラムで管理されているのは、同じテーブルにカウントを
持つシンプルな設計で把握しやすかった。
`str_pad` を忘れると `42` → `42` になってしまうバグに気づいてよかった。」

★★★★☆ — 身近な機能で実用的な暗号パターンが学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel の Auth::attempt() + Sanctum に相当する機能を素の PHP で書く体験。
`findOrCreateUser` パターンは `firstOrCreate` と同じ発想で直感的。
OTP レコードに `attempt_count` を持たせる設計は、Laravel では Cache::increment() で
レート制限することが多いが、DB 管理の方が永続性と監査性が高いというトレードオフを理解した。
最新 OTP のみ有効にする `ORDER BY id DESC LIMIT 1` は Eloquent の `->latest()->first()` に
相当するシンプルなパターン。MySQL 統合テストで `AppFactory::createMysql()` を使う設計が
テスト環境の切り替えをきれいに分離していると感じた。」

★★★★☆ — Laravel ユーザーに馴染みある発想で NENE2 の設計が理解できる

### Persona 3 — セキュリティエンジニア

「コード保存: SHA-256 ハッシュのみ保存、生コード非保存 ✓
タイミング攻撃防止: `hash_equals()` 使用 ✓
列挙防止: `/otp/request` が常に 202 ✓
ブルートフォース: 3 回失敗でロックアウト ✓
リプレイ防止: `used_at` で使用済みチェック ✓
セッション: 256-bit ランダムトークン、ハッシュ保存 ✓
古いコード無効化: 最新 OTP のみ使用 ✓
気になる点: メールアドレス形式の検証は `filter_var(FILTER_VALIDATE_EMAIL)` + 長さ制限あり。
ただし internationalized email (IDN) の扱いは未考慮。本番では追加検証を推奨。
また `locked_until` はリクエスト時ではなく検証時に設定されるため、
ロック解除後に新しい OTP を発行するとロックがリセットされる。
本番では OTP と独立したロックアウトカウンターを users テーブルに持つことを検討すべき。」

★★★★☆ — 主要なセキュリティ対策は網羅。本番はロックアウト設計要検討

### Persona 4 — フロントエンド開発者（API 利用者）

「ログインフォームの実装がシンプル。
Step 1: メール入力 → POST /otp/request → 202 → 『コードをメールに送りました』UI
Step 2: コード入力 → POST /otp/verify → 200 → session_token をローカルストレージに保存
Step 3: 各リクエストに `Authorization: Bearer <token>` を付ける
Step 4: GET /otp/session で定期的にトークン有効性を確認
Step 5: DELETE /otp/session でログアウト
422 と 401 の区別がはっきりしている（フォームバリデーションエラー vs 認証失敗）ので
エラーメッセージ出し分けが楽。429 のロックアウトは UI でカウントダウン表示が必要なのが
唯一の複雑さ。」

★★★★☆ — 2ステップログインの UX が実装しやすい

### Persona 5 — インフラ・DevOps エンジニア

「`otp_codes` テーブルは認証のたびに INSERT されるため、長期運用では定期的な
期限切れレコードの PURGE が必要（`DELETE FROM otp_codes WHERE expires_at < NOW() AND used_at IS NOT NULL`）。
MySQL: `VARCHAR(64)` で code_hash / session_token_hash を保存。UNIQUE INDEX は
`otp_sessions.session_token_hash` に設定済み。
`otp_codes.user_id` に INDEX があると `ORDER BY id DESC LIMIT 1` の絞り込みが速くなる。
本番スケールでは Redis でレート制限するのが定番だが、
この DB アプローチは永続性があり監査ログとしても使える。
MySQL 統合テストが 5 件 Pass しており、SQLite → MySQL 差異のリスクは低い。」

★★★☆☆ — 本番運用はレコード purge とインデックス追加が必要

### Persona 6 — プロダクトマネージャー

「パスワードレス認証は近年の主流トレンド。ユーザーがパスワードを覚えなくてよく、
フィッシング耐性も高い。OTP のシンプルさがユーザー受け入れのハードルを下げる。
今後の拡張: SMS OTP（Twilio 等）、TOTP（Google Authenticator 連携）、
マジックリンクとの選択制（FT144 参照）。
セキュリティ観点: 3 回失敗ロックアウトはユーザーが SIM スワップ等の攻撃を受けた場合にも
有効なガード。管理者向けの強制ロック解除 API の追加も検討価値がある。
OTP の有効期限（5分）と再送間隔の設定は UX と安全性のバランスをプロダクトチームで決定すべき。」

★★★★☆ — 現代的なパスワードレス認証の基盤として実用的

---

## Howto

`docs/howto/otp-authentication.md`
