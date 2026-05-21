# Field Trial 144 — パスワードレス認証（Magic Link）

**Date**: 2026-05-21  
**App**: `magiclog`  
**Path**: `/home/xi/docker/NENE2-FT/magiclog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.78  
**Special**: 脆弱性診断（12件）+ クラッカー攻撃試験（12件）両方実施

---

## What was built

パスワードレス認証（Magic Link）システムを実装した。
パスワードなしでメールアドレスだけでログインできる。

| Endpoint | 説明 |
|---|---|
| `POST /auth/request` | メールアドレス提示 → Magic Link 生成（常に 202） |
| `POST /auth/verify` | Magic Link トークン検証 → セッショントークン発行 |
| `POST /auth/logout` | セッション無効化（常に 204） |
| `GET /me` | 認証済みユーザー情報取得 |

---

## Architecture decisions

### トークンは SHA-256 ハッシュで保存

Magic Link トークンも Session トークンも、DB には生値を保存しない。
`bin2hex(random_bytes(32))` で 256-bit ランダム hex 文字列を生成し、`hash('sha256', $rawToken)` のみ保存。
DB 漏洩時にトークンから生値を復元できない。

### ユーザー列挙防止（常に 202）

`POST /auth/request` は登録済みメールでも未登録メールでも常に 202 を返す。
攻撃者がメールアドレスの登録状況を確認できない。

### expiry チェックは used_at チェックより先

```php
if ($now > (string) $link['expires_at']) { return 401 'expired'; }
if ($link['used_at'] !== null) { return 401 'already used'; }
```

期限切れトークンが「使用済みかどうか」という情報を漏らさない。

### 新規ユーザー自動作成（findOrCreate）

初回ログイン時にユーザーレコードを自動作成する。パスワードレス認証の特性として
ユーザー登録フロー自体が不要（認証 = 登録）。

### logout は常に 204

セッションが存在するかどうかを漏らさない。有効なセッションがあれば無効化する。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `MagicTest.php` (SQLite) | 19 | Pass |
| `VulnTest.php` (脆弱性診断) | 12 | Pass |
| `AttackTest.php` (攻撃試験) | 12 | Pass |
| **Total** | **43** | **Pass** |

---

## 脆弱性診断 (FT144)

| ID | 脆弱性項目 | 結果 |
|---|---|---|
| VULN-A | 期限切れトークンは used_at チェックより先に弾かれる | Pass |
| VULN-B | Session トークンは SHA-256 ハッシュで保存（生値は DB にない） | Pass |
| VULN-C | 使用済み Magic Link の再使用防止（リプレイ攻撃） | Pass |
| VULN-D | logout 後のセッションで /me が 401 | Pass |
| VULN-E | 存在しないメールでも 202（ユーザー列挙防止） | Pass |
| VULN-F | revoked セッションは /me で 401 | Pass |
| VULN-G | 期限切れセッションは /me で 401 | Pass |
| VULN-H | Magic Link トークンも SHA-256 ハッシュ保存 | Pass |
| VULN-I | Magic Link 有効期限は 15 分以内 | Pass |
| VULN-J | Session トークンに有効期限が設定されている | Pass |
| VULN-K | Session トークンは 64 文字以上の hex（256-bit） | Pass |
| VULN-L | X-User-Id ヘッダーで認証バイパスできない | Pass |

**12/12 Pass**

---

## クラッカー攻撃試験 (FT144)

| ID | 攻撃手法 | 結果 |
|---|---|---|
| ATK-01 | ランダムトークンで verify ブルートフォース | Pass（全て 401） |
| ATK-02 | 期限切れ Magic Link での verify | Pass（401 + expired メッセージ） |
| ATK-03 | 使用済み Magic Link の再利用（リプレイ） | Pass（401 + already been used） |
| ATK-04 | 無効なメール形式（5種類）でリクエスト | Pass（全て 422） |
| ATK-05 | 空トークンで verify | Pass（422） |
| ATK-06 | email フィールドへの SQL インジェクション | Pass（422 or 202、テーブル破壊なし） |
| ATK-07 | 極端に長いメールアドレス（300文字+） | Pass（422） |
| ATK-08 | revoked セッションで /me アクセス | Pass（401） |
| ATK-09 | 他ユーザーのセッショントークンで /me | Pass（正しいユーザーデータのみ返す） |
| ATK-10 | 期限切れセッションで /me アクセス | Pass（401） |
| ATK-11 | logout 後の同セッション再利用 | Pass（401） |
| ATK-12 | X-User-Id ヘッダーだけで /me（認証バイパス試行） | Pass（401） |

**12/12 Pass**

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「パスワードレス認証は Slack の Magic Link や Apple の Sign-in with Apple で知っていたが、
実装したことがなかった。コードを見ると意外とシンプルで、『トークンを生成してハッシュを DB に保存し、
メールで送る』という流れが明快だった。`bin2hex(random_bytes(32))` という書き方が安全な乱数生成の
標準的な方法だと知れた。SHA-256 で保存する理由（DB 漏洩時の安全性）が最初ピンとこなかったが、
パスワードハッシュと同じ考え方だと説明されて納得した。有効期限（15 分）や一回限り使用の概念も
セキュリティの基本として理解できた。」

★★★★☆ — セキュリティ概念の入門として丁度よい難易度

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel Fortify の Passwordless login 機能と同等のものを自分で組んだ感じ。Laravel では
`notification` でメール送信 + `signed URL` で実現することが多いが、NENE2 ではシンプルなトークン
テーブルで同等のことができた。`findOrCreateUser` パターンは Eloquent の `firstOrCreate` に
相当する。`hash_equals` を使っていないことが気になったが、SHA-256 ハッシュを DB から引いて
比較しているので厳密な意味でのタイミング攻撃リスクは低いと判断できる（`findByHash` が 0/1 行
返すため）。expiry を used_at より先にチェックする設計は Laravel でも意識すべき点。」

★★★★☆ — パターンの一貫性が理解を助ける

### Persona 3 — セキュリティエンジニア

「256-bit ランダムトークン + SHA-256 ハッシュ保存は標準的な正しい設計。トークン生成に
`random_bytes()` を使っているので暗号論的安全性が確保されている。ユーザー列挙防止（常に 202）、
リプレイ攻撃防止（used_at）、セッション無効化（revoked_at）がすべて実装されている。
気になる点は 2 点: (1) `POST /auth/request` にレートリミットがない — 大量メール送信攻撃が可能。
(2) Magic Link トークンを DB から引くとき `findByHash` でハッシュ全体を比較しているが、
strict constant-time compare（`hash_equals`）を明示的に使っていない。
SQL WHERE 句で比較する設計は DB 側でタイミング一定化されているためリスクは実質低いが、
明示的な `hash_equals` の方がより明確な意図を示す。FT スコープとしては十分な安全性。」

★★★★☆ — セキュリティ基礎は揃っている、レートリミットが次の課題

### Persona 4 — フロントエンド開発者（API 利用者）

「Magic Link の UX 実装に必要なものが揃っている。`POST /auth/request` で 202 を受け取ったら
『メールを確認してください』UI を表示。ユーザーがメールのリンクをクリックすると `POST /auth/verify`
で `session_token` と `expires_at` が返る — これを localStorage に保存すれば以降の API コールに
使える。`Authorization: Bearer <token>` ヘッダーで認証する点は標準的で扱いやすい。
`GET /me` でユーザー情報を取得できるのでプロフィール表示も簡単。logout は 204 なのでシンプルに
処理できる。ただし `expires_at` をクライアントで監視してリダイレクトする処理が必要になる。」

★★★★☆ — フロントの実装に必要な情報が揃っている

### Persona 5 — インフラ・DevOps エンジニア

「SQLite で動作するため開発環境の追加サービスが不要。本番では MySQL / PostgreSQL に乗り換える
想定で設計されており、SQL が標準的でポータブル。`auth_sessions` テーブルのインデックスは
`session_token_hash` の UNIQUE 制約が自動でカバーする。`magic_links` の期限切れレコードを
定期的に削除する cleanup ジョブが必要になる点は要注意（DB に蓄積し続ける）。
`UNIQUE(token_hash)` が magic_links にあるため、ハッシュ衝突（256-bit では現実的には不可能）
でも INSERT 失敗で安全に処理される。セッショントークンも同様。」

★★★☆☆ — 本番で cleanup ジョブが必要な点は明記が必要

### Persona 6 — プロダクトマネージャー

「Magic Link 認証はパスワードを覚える必要がないため、ユーザー登録の摩擦が大幅に減る。
特に偶発的な訪問者（ゲスト注文などのユースケース）に有効。15 分の有効期限は
メール開封までの実際の行動時間として適切。ただし『メールが届かない』場合のリカバリが
UX の重要課題 — 再送機能とサポートフローの設計が必要。今後の拡張として、
複数デバイスからのログイン管理、セッション一覧・個別失効、アクティビティログなどが考えられる。
パスワードレスは Google・Apple のパスキーに並ぶトレンドで戦略的に正しい方向性。」

★★★★☆ — 基本 UX は揃っている、メール不達のリカバリが課題

---

## Howto

`docs/howto/passwordless-auth-magic-link.md`
