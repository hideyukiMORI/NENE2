# DX Scenario 27: メンバーシップサイト

## アプリ概要

会員登録・コンテンツ制限・更新を管理するメンバーシップサイト API。

| 機能 | エンドポイント例 |
|------|----------------|
| 会員登録 | `POST /members`（email, password, plan_id） |
| ログイン | `POST /auth/login`（email, password → JWT） |
| プロフィール | `GET /members/me`, `PUT /members/me` |
| コンテンツ一覧 | `GET /content?category=articles`（フリー/会員限定フラグ）|
| コンテンツ取得 | `GET /content/{id}`（会員限定は認証必須） |
| プラン確認 | `GET /members/me/plan`（現在のプラン・有効期限）|
| プラン変更 | `POST /members/me/plan-change`（plan_id） |
| コンテンツ公開 | `POST /content`（admin 限定、requires_membership: true/false）|

ポイント: JWT 認証、コンテンツのアクセス制限（認証 + プラン確認）、会員プランのバリデーション。

---

## Persona A — 松本 拓実（新卒・男性・24 歳）

### 背景

Web 開発専攻の専門学校卒 1 年目。「会員制サイト」の概念は知っているが JWT は初めて。

### 作業シナリオ

1. `members` テーブルに `password` を平文で保存してしまう（最初）。
   先輩に指摘されて `password_hash` に変更。`password_auth-argon2id.md` を参照。
2. JWT は「ライブラリ使えばいい」と思い `lcobucci/jwt` を `composer require` しようとするが、
   `vendor/` 外への install 方法を調べる。
3. コンテンツ制限を「会員かどうか」チェックのみで実装（プランの有効期限チェックなし）。
4. `GET /content/{id}` で非会員に 404 vs 403 どちらを返すか迷い、403 を選ぶ。
5. admin 限定エンドポイントの認可を実装するが「ミドルウェアで admin フラグをチェックする」
   設計方法が分からず UseCase に if 文を書いてしまう。

### ハマりポイント

- **平文パスワード**: `password-auth-argon2id.md` は知らなかったが先輩に教えてもらえた。
- **JWT の実装**: NENE2 に JWT 組み込みの例があるかどうか分からず、ライブラリ依存になった。
- **有効期限チェックの漏れ**: 「会員かどうか」と「プランが有効かどうか」は別の確認が必要。

### 解決策 & 感想

`docs/howto/password-auth-argon2id.md` と `docs/howto/jwt-tenant-isolation.md` を参照。
JWT の howto があって助かった。

> 「パスワードは平文でダメって知ってたけど、
>  howto に『やり方』が書いてあったのですごく助かった。
>  JWT の howto もあったの！？探してなかった。
>  次から最初に howto 全部チェックしよう。」

### DX スコア: ⭐⭐⭐（3/5）

howto 活用で改善できる。有効期限チェックの漏れと howto の発見性が課題。

---

## Persona B — 坂口 亜矢（ロースキル・女性・32 歳）

### 背景

コンテンツメディアの IT 担当 7 年目。WordPress のメンバーシッププラグイン管理経験あり。

### 作業シナリオ

1. `members` / `plans` / `member_plans(member_id, plan_id, started_at, expires_at)` で設計。
2. ログインは `password-auth-argon2id.md` を参照して Argon2id を正しく実装。
3. JWT は `jwt-tenant-isolation.md` を参照して `NENE2_LOCAL_JWT_SECRET` で HMAC-HS256 実装。
4. コンテンツ制限:
   - `requires_membership = false` → 誰でもアクセス可
   - `requires_membership = true` → JWT 検証 + `member_plans.expires_at > now()` チェック
5. 非会員が会員限定コンテンツに触れたとき 403 を返すが、
   「コンテンツの存在自体を知られたくない」場合は 404 が適切と後で気づいた。

### ハマりポイント

- **403 vs 404 の情報隠蔽**: 会員限定コンテンツに非会員がアクセスした場合のステータスコード選択。
- **`expires_at > now()`**: SQLite の `datetime('now')` との比較で TEXT 型 ISO 8601 を使用。
  タイムゾーンが一致しているかの確認が必要。
- **プラン変更時の日割り計算**: 月次プランを途中で上位プランに変えた場合の処理（今回省略）。

### 解決策 & 感想

howto を積極活用してスムーズに実装できた。403/404 の選択は「要件次第」として両方の実装例を作った。

> 「howto が充実してて助かった。パスワードも JWT も参照できた。
>  403 と 404 の使い分けは設計方針として先に決めておくべきだと思った。
>  howto に 'セキュリティ観点での選択基準' が書いてあれば嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

howto を活用して高品質に完成。403/404 の選択基準ドキュメントが改善余地。

---

## Persona C — 西沢 元一（シニア・男性・43 歳）

### 背景

SaaS プロダクトのセキュリティエンジニア 15 年。OWASP Top 10 を熟知。

### 作業シナリオ

1. テーブル設計（セキュリティ重視）:
   - `members(id, email, password_hash, role, created_at)` — `role: member|admin`
   - `plans(id, name, price_cents, duration_days, features_json)`
   - `member_subscriptions(id, member_id, plan_id, started_at, expires_at, status)`
   - `content_access_log(content_id, member_id, accessed_at)` — アクセス監査
2. JWT は `sub=member_id, role=member, exp=1h` のクレーム設計。
   リフレッシュトークンは `refresh_tokens(member_id, token_hash, expires_at)` テーブルで管理。
3. コンテンツアクセス制御を `ContentAccessPolicy::canAccess(Member $m, Content $c)` として抽象化。
4. 非会員への会員限定コンテンツは 404（存在を隠蔽）。管理者には 403。
5. `POST /members` のレート制限を `ThrottleMiddleware` で設定。
   ブルートフォース防止のため「1 IP 10 回/分」制限。

### ハマりポイント

- **リフレッシュトークンの実装**: `refresh_tokens` テーブルで安全に管理しようとしたが、
  NENE2 の JWT howto は短命トークンのみ説明。リフレッシュは自力実装。
- **`ThrottleMiddleware` の設定**: `CLAUDE.md` にミドルウェア順序の説明あり。
  IP ベースのレート制限を実装したが、IP の取得方法（プロキシ越し）を確認。
- **`ContentAccessPolicy` の DI**: `ServiceProvider` への登録方法を `src/` で確認。

### 解決策 & 感想

高品質で完成。リフレッシュトークンは自力実装したが、howto があれば時間節約できた。

> 「JWT howto は基本的な内容で、リフレッシュトークンは書いてなかった。
>  プロダクション用の JWT 設計（有効期限・リフレッシュ・失効）の howto が欲しい。
>  ThrottleMiddleware の使い方も howto があれば嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。JWT リフレッシュトークンと ThrottleMiddleware の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 松本（新卒） | ○ howto 活用で改善 | 3/5 | howto の発見性・有効期限チェック漏れ |
| 坂口（ロースキル） | ◎ howto 活用で高品質 | 4/5 | 403 vs 404 の情報隠蔽基準 |
| 西沢（シニア） | ◎ 高品質完成 | 4/5 | JWT リフレッシュ、ThrottleMiddleware 設定 |

**共通のフリクション**:
1. **howto の発見性** — `password-auth-argon2id.md` / `jwt-tenant-isolation.md` は充実しているが、
   「メンバーシップを作る」文脈から自然に辿り着ける索引が必要。
2. **JWT リフレッシュトークン** — 短命 access token + 長命 refresh token のパターン howto。
3. **403 vs 404 の情報隠蔽** — セキュリティ観点での選択基準を howto に明示。
