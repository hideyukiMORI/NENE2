# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.5.190] — 2026-05-27

### Added
- `docs/howto/product-catalog.md` — 商品カタログ API ガイド: SKU 管理・キーワード検索・ソフトデリート・ATK-01~12 全Pass (FT212)。 (#969)

---


## [1.5.158] — 2026-05-27

### Added
- `docs/howto/leaderboard-ranking.md` — ゲームリーダーボード API ガイド: スコア投稿・MAX(score) GROUP BY ランキング・個人ベスト・ゲーム別分離・limit クランプ・is_int スコア検証 (FT206)。 (#957)

---

## [1.5.157] — 2026-05-27

### Added
- `docs/howto/notification-inbox.md` — 通知 inbox API ガイド: 型 allowlist・IDOR 防止 (404)・mark-as-read オーナー検証・一括既読・ページネーションクランプ・管理者 fail-closed (FT222 VULN トリガー)。 (#989)

---

## [1.5.156] — 2026-05-27

### Added
- `docs/howto/invitation-referral.md` — 招待・リファラル API ガイド: トークン as capability パターン・bin2hex(random_bytes(16)) 生成・有効期限・1回限り使用・IDOR 防止 (自分の招待のみ) (FT221)。 (#987)

---

## [1.5.155] — 2026-05-27

### Added
- `docs/howto/inventory-management.md` — 在庫管理 API ガイド: SKU パターン検証・signed delta 調整・在庫不足 409・調整履歴ログ・ATK-01〜12 全Pass (FT220 ATK クラッカー攻撃テストトリガー)。 (#985)

---

## [1.5.154] — 2026-05-27

### Added
- `docs/howto/activity-feed.md` — アクティビティフィード API ガイド: 型 allowlist (in_array strict)・JSON payload ストレージ・IDOR 防止 (404)・ページネーション・型フィルター・VULN-B〜I 全Pass (FT219 VULN トリガー)。 (#983)

---

## [1.5.153] — 2026-05-27

### Added
- `docs/howto/coupon-redemption.md` — クーポン引き換え API ガイド: コードパターン検証・使用上限・有効期限・ユーザーごと 1回制限 (UNIQUE constraint)・atomic インクリメント・match 式分岐 (FT218)。 (#981)

---

## [1.5.152] — 2026-05-27

### Added
- `docs/howto/poll-survey.md` — 投票・アンケート API ガイド: 1ユーザー1票制 (UNIQUE constraint)・クロスポールオプション注入防止・LEFT JOIN 集計・プライベートポール 404 返却・is_bool/is_int 厳格検証 (FT217)。 (#979)

---

## [1.5.151] — 2026-05-27

### Added
- `docs/howto/resource-reservation.md` — リソース予約 & タイムスロット予約ガイド: 重複検出 SQL (半開区間)・readonly value object・公開/管理者ビュー分離による IDOR 防止・キャンセル所有権検証・VULN-A〜L + ATK-01〜12 全Pass (FT216 デュアルトリガー)。 (#977)

---

## [1.5.150] — 2026-05-27

### Added
- `docs/howto/order-management.md` — 注文管理 API ガイド: 複数行アイテム・自動合計計算・ステータスライフサイクル (pending→cancelled)・IDOR 防止 (404 返却)・管理者オーバーライド・二重キャンセル競合検出 409 (FT215)。 (#975)

---

## [1.5.114] — 2026-05-26

### Added
- `docs/howto/tenant-isolation.md` — テナント分離 & クロステナント IDOR 防止ガイド: SQL レベルの tenant_id スコープ・ヘッダーベース認証・ボディインジェクション防止・404 vs 403 戦略。ATK-01〜12 全Pass (FT179)。 (#901)


---

## [1.5.113] — 2026-05-26

### Added
- `docs/howto/json-merge-patch.md` — JSON Merge Patch & ETag ガイド: RFC 7396 null セマンティクス・不変フィールド保護・If-Match 競合検出 412・V.php 実戦投入・`?? ''` トラップ・Router::param() 正しい使い方。ATK-01〜12 全Pass (FT178)。 (#899)

---

## [1.5.112] — 2026-05-26

### Added
- `src/Validation/V.php` — HTTP パラメータ検証ヘルパー: queryInt/bodyInt/str/isoDatetime/futureDatetime/enum/userId/secret の8メソッド。フレームワーク非依存の純粋 PHP ユーティリティ、将来 hideyukimori/nene-validate として独立抽出可能な構造。63 tests / 102 assertions。 (#897)

---

## [1.5.111] — 2026-05-26

### Added
- `docs/howto/pagination-boundary-attack.md` — Pagination Boundary Attack ガイド: ctype_digit O(n) vs regex ReDoS・overflow guard strlen>18・clampInt パターン・VULN-A〜L 全Pass (FT177)。 (#898)

---

## [1.5.110] — 2026-05-26

### Added
- `docs/howto/delegated-access-grants.md` — Delegated Access Grants ガイド: multi-party 委譲・time-limited scoped アクセス・state machine（expired/revoked）・IDOR防止 404・型強制チェック（is_int 厳密検証）・Unicode/BIDI verbatim 保存。**クラッカー攻撃試験 ATK-01〜12 全Pass** (FT176)。 (#895)

---

## [1.5.109] — 2026-05-26

### Added
- `docs/howto/api-usage-metering.md` — API Usage Metering ガイド: per-user 日次クォータ・usage_events 追記ログ・day_key インデックス・ゲートチェック・エンドポイント別内訳。脆弱性診断 VULN-A〜L 全Pass (FT175)。 (#893)

---

## [1.5.108] — 2026-05-26

### Added
- `docs/howto/slug-management.md` — Slug Management ガイド: SlugHelper（fromTitle/makeUnique）・slug_history テーブル・301 リダイレクトヒント・更新時衝突解決 (FT174)。 (#890)

---

## [1.5.107] — 2026-05-26

### Added
- `docs/howto/content-relations.md` — Content Relations ガイド: 型付きM:N自己参照リンク（related/sequel/prequel/reference）・双方向自動挿入・逆辺カスケード削除 (FT173)。 (#888)

---

## [1.5.106] — 2026-05-26

### Added
- `docs/howto/content-scheduling.md` — Content Scheduling ガイド: publish_at 時間指定・draft/scheduled/published/archived 状態機械・publish-due 一括トリガー・hash_equals admin key。脆弱性診断 VULN-A〜L 全Pass・クラッカー攻撃試験 ATK-01〜12 全Pass (FT172)。 (#886)

---

## [1.5.105] — 2026-05-26

### Added
- `docs/howto/hierarchical-data.md` — Hierarchical Data ガイド: 自己参照FK + マテリアライズドパス・MAX_DEPTH=5・循環参照検出・サブツリーカスケード (FT171)。 (#884)

---

## [1.5.104] — 2026-05-22

### Added
- `docs/howto/request-deduplication.md` — Request Deduplication ガイド: Idempotency-Key 必須・24h TTL・replayed フラグ。クラッカー攻撃試験 ATK-01〜12 全Pass。 (#845)

---

## [1.5.103] — 2026-05-22

### Added
- `docs/howto/data-masking.md` — Data Masking ガイド: デフォルトマスク・admin unmask・X-Accessor 強制・append-only 監査ログ。脆弱性診断 VULN-A〜L 全Pass。 (#843)

---

## [1.5.102] — 2026-05-22

### Added
- `docs/howto/admin-report-aggregation.md` — Admin Report Aggregation ガイド: 日付バリデーション・from>to 拒否・limit クランプ。クラッカー攻撃試験 ATK-01〜12 全Pass。 (#841)

---

## [1.5.101] — 2026-05-22

### Added
- `docs/howto/inbound-webhook-receiver.md` — Inbound Webhook Receiver ガイド: per-source HMAC secret・署名検証→冪等→保存順序。MySQL 統合テスト 5件全Pass。 (#839)

---

## [1.5.100] — 2026-05-22

### Added
- `docs/howto/multi-step-workflow.md` — Multi-step Workflow ガイド: 順序付きステップ・approve/reject 遷移・アクション履歴。 (#837)

---

## [1.5.99] — 2026-05-22

### Added
- `docs/howto/ab-testing.md` — A/B Testing ガイド: draft→active→stopped 遷移・crc32 決定論的割当・CVR 集計。 (#835)

---

## [1.5.98] — 2026-05-22

### Added
- `docs/howto/geolocation.md` — Geolocation ガイド: Haversine 距離・バウンディングボックス・座標バリデーション。クラッカー攻撃試験 ATK-01〜12 全Pass。 (#833)

---

## [1.5.97] — 2026-05-22

### Added
- `docs/howto/payment-webhook.md` — Payment Webhook ガイド: HMAC-SHA256 署名検証・event_id 冪等処理・ステータス遷移ガード 409。 (#831)

---

## [1.5.96] — 2026-05-22

### Added
- `docs/howto/content-versioning.md` — Content Versioning ガイド: append-only 履歴・バージョン一覧・ロールバック = 新バージョン。 (#829)

---

## [1.5.95] — 2026-05-22

### Added
- `docs/howto/application-caching.md` — Application Caching ガイド: Cache-Aside・TTL クロック注入・書き込み時無効化。 (#827)

---

## [1.5.94] — 2026-05-22

### Added
- `docs/howto/oauth2-social-login.md` — OAuth2 Social Login ガイド: Authorization Code Flow・state CSRF 防止・コードリプレイ防止。クラッカー攻撃試験 ATK-01〜12 全Pass。 (#825)

---

## [1.5.93] — 2026-05-22

### Added
- `docs/howto/totp-authentication.md` — TOTP 二要素認証ガイド: RFC 6238・Base32・リプレイ防止 used_totp_steps。脆弱性診断 VULN-A〜L 全Pass。 (#823)

---

## [1.5.92] — 2026-05-22

### Added
- `docs/howto/csv-bulk-import.md` — CSV バルクインポートガイド: str_getcsv $escape 必須化・部分成功・バッチ内重複検知。MySQL 統合テスト 5件全Pass。 (#821)

---

## [1.5.91] — 2026-05-22

### Added
- `docs/howto/search-autocomplete.md` — 全文検索・オートコンプリートガイド: LIKE 特殊文字エスケープ・関連度スコア 3段階・limit クランプ。 (#819)

---

## [1.5.90] — 2026-05-22

### Added
- `docs/howto/file-metadata-sharing.md` — ファイルメタデータ管理・共有 API ガイド: 3段階アクセス制御・IDOR防止・visibility エスカレーション防止。脆弱性診断 VULN-A〜L 全Pass / クラッカー攻撃試験 ATK-01〜12 全Pass。 (#816)

---

## [1.5.89] — 2026-05-21

### Added
- `docs/howto/shopping-cart.md` — ショッピングカートガイド: UNIQUE制約・数量加算冪等・quantity=0削除・price都度計算。 (#814)
- `docs/field-trials/2026-05-field-trial-155.md` — FT155 レポート: cartlog（カート管理、28 tests）。 (#814)

---

## [1.5.88] — 2026-05-21

### Added
- `docs/howto/product-review-system.md` — プロダクトレビュー・評価システムガイド: 1ユーザー1商品1レビュー・集計・所有権チェック。 (#813)
- `docs/field-trials/2026-05-field-trial-154.md` — FT154 レポート: reviewlog（レビュー評価、29 tests）。 (#813)

---

## [1.5.87] — 2026-05-21

### Added
- `docs/howto/activity-feed.md` — アクティビティフィードガイド: フォローベースフィード・カーソルページネーション・プライバシー制御・actor_id注入防止。 (#811)
- `docs/field-trials/2026-05-field-trial-153.md` — FT153 レポート: feedlog（アクティビティフィード、57 tests = 40 正常 + 12 脆弱性診断 + 5 MySQL統合）**脆弱性診断: VULN-A〜L 全Pass** / **MySQL統合テスト: 5件全Pass**。 (#811)

---

## [1.5.86] — 2026-05-21

### Added
- `docs/howto/point-loyalty-system.md` — ポイント・ロイヤルティシステムガイド: トランザクション履歴残高・冪等化reference_id・残高多層防御・MAX_EARN制限・admin調整。 (#809)
- `docs/field-trials/2026-05-field-trial-152.md` — FT152 レポート: pointlog（ポイント・ロイヤルティ、30 tests = 18 正常 + 12 攻撃試験）**クラッカー攻撃試験: ATK-01〜12 全Pass**。 (#809)

---

## [1.5.85] — 2026-05-21

### Added
- `docs/howto/wishlist-management.md` — ウィッシュリスト管理ガイド: 存在非公開・冪等追加・priority/noteメタデータ・フォールバックバリデーション。 (#807)
- `docs/field-trials/2026-05-field-trial-151.md` — FT151 レポート: wishlistlog（ウィッシュリスト管理、23 tests）。 (#807)

---

## [1.5.84] — 2026-05-21

### Added
- `docs/howto/coupon-promo-code.md` — クーポン・プロモコード管理ガイド: admin RBAC・ユーザーごとの利用追跡・discount_pct範囲制約・user_id注入防止。 (#805)
- `docs/field-trials/2026-05-field-trial-150.md` — FT150 レポート: couponlog（クーポン・プロモコード管理、34 tests = 22 正常 + 12 脆弱性診断）**脆弱性診断: VULN-A〜L 12件全Pass**。 (#805)

---

## [1.5.83] — 2026-05-21

### Added
- `docs/howto/content-collection.md` — コンテンツコレクションガイド: 存在非公開パターン（404 vs 403）・冪等アイテム追加（201/200）・位置詰め整合・複数パスパラメータ。 (#803)
- `docs/field-trials/2026-05-field-trial-149.md` — FT149 レポート: collectionlog（コンテンツコレクション、20 tests）。 (#803)

---

## [1.5.82] — 2026-05-21

### Added
- `docs/howto/otp-authentication.md` — OTP認証ガイド: 6桁コード生成・SHA-256ハッシュ保存・ブルートフォース対策・ロックアウト・セッション管理・MySQL対応・setRiskyAllowed(true)。 (#801)
- `docs/field-trials/2026-05-field-trial-148.md` — FT148 レポート: otplog（OTP認証、35 tests = 18 正常 + 12 攻撃試験 + 5 MySQL）**クラッカー攻撃試験: 12件全Pass** / **MySQL統合テスト: 5件全Pass**。 (#801)

---

## [1.5.81] — 2026-05-21

### Added
- `docs/howto/content-report-moderation.md` — コンテンツ通報・モデレーションガイド: RBAC・IDOR防止・冪等通報（201/200）・一方向ステータス遷移・Router::param()パターン。 (#799)
- `docs/field-trials/2026-05-field-trial-147.md` — FT147 レポート: reportlog（コンテンツ通報・モデレーション、32 tests、脆弱性診断 VULN-A〜L）。 (#799)

---

## [1.5.80] — 2026-05-21

### Added
- `docs/howto/content-pinning.md` — コンテンツピン留めガイド: position連続管理・冪等追加（201/200）・unpin後の位置詰め・完全一致reorder。 (#796)
- `docs/field-trials/2026-05-field-trial-146.md` — FT146 レポート: pinlog（コンテンツピン留め、19 tests）。 (#796)

---

## [1.5.79] — 2026-05-21

### Added
- `docs/howto/user-preferences-management.md` — ユーザー設定管理ガイド: PreferenceKey enum・型バリデーション・upsert・デフォルト値フォールバック・IDOR防止。 (#794)
- `docs/field-trials/2026-05-field-trial-145.md` — FT145 レポート: preflog（ユーザー設定管理、20 tests）。 (#794)

---

## [1.5.78] — 2026-05-21

### Added
- `docs/howto/passwordless-auth-magic-link.md` — パスワードレス認証（Magic Link）ガイド: SHA-256ハッシュ保存・ユーザー列挙防止（常に202）・expiry before used_at・セッション無効化。 (#792)
- `docs/field-trials/2026-05-field-trial-144.md` — FT144 レポート: magiclog（パスワードレス認証、43 tests = 19 正常 + 12 脆弱性診断 + 12 クラッカー攻撃）**脆弱性診断: 12件全Pass** / **クラッカー攻撃試験: 12件全Pass**。 (#792)

---

## [1.5.77] — 2026-05-21

### Added
- `docs/howto/emoji-reaction-system.md` — 絵文字リアクションガイド: UNIQUE(post_id, user_id, emoji)・GROUP BY集計・optional actor per-user tracking・mb_strlen絵文字長・is_array常にtrue回避。 (#790)
- `docs/field-trials/2026-05-field-trial-143.md` — FT143 レポート: emojilog（絵文字リアクション、23 tests = 18 正常 + 5 MySQL）**MySQL統合テスト: 5テスト**。 (#790)

---

## [1.5.76] — 2026-05-21

### Added
- `docs/howto/content-draft-lifecycle.md` — コンテンツ下書きライフサイクルガイド: ArticleStatus enum遷移ガード・非公開記事の404隠蔽・同秒ソート安定性（ORDER BY published_at DESC, id DESC）。 (#788)
- `docs/field-trials/2026-05-field-trial-142.md` — FT142 レポート: draftlog（下書き管理、20 tests）6 ペルソナ DX レビュー。 (#788)

---

## [1.5.75] — 2026-05-21

### Added
- `docs/howto/leaderboard-ranking-system.md` — リーダーボードガイド: ベストスコア UPDATE パターン・COUNT(*)ランク計算・スコア所有権チェック（IDOR防止）・limitクランプ・型混乱（float/string）防止。 (#786)
- `docs/field-trials/2026-05-field-trial-141.md` — FT141 レポート: ranklog（リーダーボード、29 tests = 17 正常 + 12 脆弱性診断）**脆弱性診断: 12件全Pass**。 (#786)

---

## [1.5.74] — 2026-05-21

### Added
- `docs/howto/flash-sale-system.md` — フラッシュセールシステムガイド: COUNT(*)残数計算・ISO 8601時間比較・UNIQUE制約二重購入防止・match式ステータス・時間逆転バリデーション。 (#784)
- `docs/field-trials/2026-05-field-trial-140.md` — FT140 レポート: salelog（フラッシュセール、29 tests = 17 正常 + 12 攻撃）**クラッカー攻撃試験: 12件全Pass**。 (#784)

---

## [1.5.73] — 2026-05-21

### Added
- `docs/howto/guest-order-system.md` — ゲスト注文システムガイド: price snapshot in order_items・在庫先行検証・カート数量加算（UPDATE on conflict）・ユーザー別カート分離・array_sum 合計計算。 (#782)
- `docs/field-trials/2026-05-field-trial-139.md` — FT139 レポート: orderlog（カート→注文→明細、23 tests）6 ペルソナ DX レビュー。 (#782)

---

## [1.5.72] — 2026-05-21

### Added
- `docs/howto/group-membership-management.md` — グループメンバーシップ管理ガイド: `user_groups` テーブル名（MySQL予約語回避）・MemberRole enum権限メソッド・owner自動追加・自己脱退・MySQL FK teardown パターン。 (#780)
- `docs/field-trials/2026-05-field-trial-138.md` — FT138 レポート: grouplog（グループメンバーシップ、38 tests = 21 正常 + 12 脆弱性診断 + 5 MySQL）**脆弱性診断: 12件全Pass** / **MySQL統合テスト: 5テスト** (#780)

---

## [1.5.71] — 2026-05-21

### Added
- `docs/howto/subscription-plan-management.md` — サブスクリプション管理ガイド: UNIQUE制約でのre-subscribe（UPDATE）・キャンセル後の再加入・PUT要アクティブ制約・409 vs 404。 (#778)
- `docs/field-trials/2026-05-field-trial-137.md` — FT137 レポート: planlog（サブスクリプション、20 tests）6 ペルソナ DX レビュー。 (#778)

---

## [1.5.70] — 2026-05-21

### Added
- `docs/howto/access-token-management.md` — アクセストークン管理ガイド: SHA-256 ハッシュ保存・TokenScope enum・二重所有権チェック・revoke 409・verify 常に 200・isset+null PHPStan 注意点。 (#776)
- `docs/field-trials/2026-05-field-trial-136.md` — FT136 レポート: tokenlog（アクセストークン、29 tests = 17 正常 + 12 攻撃）**クラッカー攻撃試験: 12 攻撃全耐久（ATK-04 二重 IDOR を確認）**。 (#776)

---

## [1.5.69] — 2026-05-21

### Added
- `docs/howto/direct-messaging-system.md` — DM システムガイド: 双方向会話検索・参加者アクセス制御・GET リクエストで parse() を呼ばない注意点・メッセージ昇順ソート。 (#774)
- `docs/field-trials/2026-05-field-trial-135.md` — FT135 レポート: messagelog（DM システム、31 tests = 19 正常 + 12 脆弱性診断）**脆弱性診断: IDOR・SQLインジェクション・XSS 全耐久、GET parse() バグ修正**。 (#774)

---

## [1.5.68] — 2026-05-21

### Added
- `docs/howto/user-follow-system.md` — フォローシステムガイド: 冪等フォロー（201/200）・自己フォロー防止（422）・フォロワー/フォロイーリスト・相互フォロー確認。 (#772)
- `docs/field-trials/2026-05-field-trial-134.md` — FT134 レポート: followlog（ユーザーフォロー、20 tests）6 ペルソナ DX レビュー。 (#772)

---

## [1.5.67] — 2026-05-21

### Added
- `docs/howto/bookmark-system.md` — ブックマークシステムガイド: 冪等 add・DatabaseConstraintException catch・コレクションフィルタ・204 vs 404・MySQL スキーマ差分。 (#770)
- `docs/field-trials/2026-05-field-trial-133.md` — FT133 レポート: bookmarklog（ブックマーク、22 tests = 17 SQLite + 5 MySQL）**MySQL 統合テスト: 5テスト**。 (#770)

---

## [1.5.66] — 2026-05-21

### Added
- `docs/howto/user-profile-management.md` — プロフィール管理ガイド: avatar_url https 強制・DatabaseConstraintException catch・mb_strlen・X-User-Id 所有権チェック。 (#768)
- `docs/field-trials/2026-05-field-trial-132.md` — FT132 レポート: profilelog（プロフィール管理、32 tests = 20 正常 + 12 攻撃）**クラッカー攻撃試験: 12 攻撃全耐久** / **脆弱性診断: VULN-A 重複メール 500 → 409 修正**。 (#768)

---

## [1.5.65] — 2026-05-21

### Added
- `docs/howto/voting-system.md` — 投票システムガイド: upvote/downvote トグル・VoteDirection enum・UNIQUE 制約・スコア同梱・ユーザー投票状態取得。 (#766)
- `docs/field-trials/2026-05-field-trial-131.md` — FT131 レポート: votelog（投票システム、20 tests）。 (#766)

---

## [1.5.64] — 2026-05-21

### Added
- `docs/howto/notification-inbox.md` — 通知インボックスガイド: `read_at` nullable パターン・冪等マーク・クロスユーザー 404・`ORDER BY id DESC`・bulk mark-as-read。 (#764)
- `docs/field-trials/2026-05-field-trial-130.md` — FT130 レポート: notificationlog（通知インボックス、23 tests）。 (#764)

---

## [1.5.63] — 2026-05-21

### Added
- `docs/howto/event-sourcing.md` — イベントソーシングガイド: append-only イベントログ・リプレイ・amount 型バリデーション・セキュリティプロパティ。 (#762)
- `docs/field-trials/2026-05-field-trial-129.md` — FT129 レポート: eventsourcelog（イベントソーシング、17 tests、脆弱性診断: VULN-A 整数オーバーフロー修正）。 (#762)

---

## [1.5.62] — 2026-05-21

### Added
- `docs/howto/account-lockout.md` — アカウントロックアウトガイド: per-account 失敗カウント・locked_until・423 Locked・ユーザー列挙防止・MySQL スキーマ・MySQL 統合テスト手順。 (#760)
- `docs/field-trials/2026-05-field-trial-128.md` — FT128 レポート: lockoutlog（アカウントロックアウト、32 tests = 27 SQLite + 5 MySQL、クラッカー攻撃試験 12 攻撃全耐久、MySQL 統合テスト初導入）。 (#760)
- `NENE2-FT/docker-compose.yml` — FT 用 MySQL サービス定義（nene2-ft_default ネットワーク、5FT ごとの MySQL テストで使用）。 (#760)

---

## [1.5.61] — 2026-05-21

### Added
- `docs/howto/threaded-comments.md` — スレッドコメントガイド: 自己参照ツリー・depth 非正規化・ソフトデリート・2パスツリー組み立て・PHPStan-safe readonly 設計。 (#758)
- `docs/field-trials/2026-05-field-trial-127.md` — FT127 レポート: commentlog（スレッドコメント、20 tests、PHPStan level 8）。 (#758)

---

## [1.5.60] — 2026-05-21

### Added
- `docs/howto/password-reset.md` — パスワードリセットガイド: SHA-256 ハッシュ保存・ユーザー列挙防止・有効期限・ワンタイム使用・旧トークン無効化。 (#756)
- `docs/field-trials/2026-05-field-trial-126.md` — FT126 レポート: resetlog（パスワードリセット、15 tests、脆弱性診断: VULN-A user_id 露出修正）。 (#756)

---

## [1.5.59] — 2026-05-21

### Added
- `docs/howto/tagging-system.md` — M:N タグシステムガイド: join table の設計・原子的タグ差し替え（delete-then-insert）・N+1 防止の IN クエリバッチ取得・タグ別 JOIN 検索。 (#752)
- `docs/field-trials/2026-05-field-trial-125.md` — FT125 レポート: taglog（タグシステム M:N、20 tests、PHPStan level 8）。 (#752)

---

## [1.5.58] — 2026-05-21

### Added
- `docs/howto/user-invitation.md` — ユーザー招待システムガイド: bin2hex(random_bytes(32)) による 256-bit トークン・expiry before status check（410 → 409 の正しい順序）・cancel の 403 vs 404 設計・owner enforcement パターン。 (#750)
- `docs/field-trials/2026-05-field-trial-124.md` — FT124 レポート: invitelog（ユーザー招待、14 functional + 12 cracker attack tests）。クラッカー攻撃試験 12 ケース全耐久。 (#750)

---

## [1.5.57] — 2026-05-21

### Added
- `docs/howto/personal-data-export.md` — 個人データエクスポートガイド: opaque token（bin2hex random_bytes(32)）・センシティブフィールド除外（phone / password_hash）・expiry 二重チェック（process + download）・410 vs 404 設計。 (#748)
- `docs/field-trials/2026-05-field-trial-123.md` — FT123 レポート: exportlog（個人データエクスポート、19 tests）。脆弱性診断: 期限切れエクスポートの孤児 PII レコード生成を修正。 (#748)

---

## [1.5.56] — 2026-05-21

### Added
- `docs/howto/distributed-locking.md` — 分散ロックガイド: owner 強制（取得・解放・更新すべて owner トークン必須）・stale lock claim（期限切れロックを新 owner が上書き取得）・非ブロッキング取得（acquired: false を即返す）・409 vs 403 設計。 (#746)
- `docs/field-trials/2026-05-field-trial-122.md` — FT122 レポート: distlocklog（分散ロック、16 tests）、6ペルソナ DX レビュー復活（FT122〜）。 (#746)

---

## [1.5.55] — 2026-05-21

### Added
- `docs/howto/feature-flags.md` — フィーチャーフラグガイド: 評価優先順位（user target → tenant target → globally_enabled → rollout_pct hash → false）・crc32 バケット割り当て・ターゲット upsert パターン・kill switch パターン。 (#744)
- `docs/field-trials/2026-05-field-trial-121.md` — FT121 レポート: featureflaglog（フィーチャーフラグ、21 tests）。 (#744)

---

## [1.5.54] — 2026-05-21

### Added
- `docs/howto/webhook-delivery.md` — アウトバウンド Webhook 配信ガイド: SSRF URL 検証・timestamp 付き HMAC（リプレイ防止）・secret ハッシュ保存・指数バックオフリトライ。 (#742)
- `docs/field-trials/2026-05-field-trial-120.md` — FT120 レポート: webhookdeliverylog（Webhook 配信、31 tests）。クラッカー攻撃試験 16 ケース全耐久。null safety 脆弱性修正。 (#742)

---

## [1.5.53] — 2026-05-21

### Added
- `docs/howto/circuit-breaker.md` — サーキットブレーカーガイド: Closed/Open/Half-Open の 3 状態遷移・lazy Half-Open（次リクエストで移行）・DB 永続化・連続失敗カウント・503 応答。 (#740)
- `docs/field-trials/2026-05-field-trial-119.md` — FT119 レポート: circuitlog（サーキットブレーカー、15 tests）。 (#740)

---

## [1.5.52] — 2026-05-21

### Added
- `docs/howto/signed-urls.md` — 署名付き URL ガイド: HMAC-SHA256 トークン・hash_equals 必須（タイミング攻撃防止）・410 Gone vs 401 設計・stateless 検証・secret rotation パターン。 (#738)
- `docs/field-trials/2026-05-field-trial-118.md` — FT118 レポート: signedlog（署名付き URL、17 tests）。 (#738)

---

## [1.5.51] — 2026-05-21

### Added
- `docs/howto/api-key-management.md` — API キー管理ガイド: SHA-256 ハッシュ保存・prefix+hash_equals 2 段階認証・スコープ階層・rotate は create-first パターン。 (#736)
- `docs/field-trials/2026-05-field-trial-117.md` — FT117 レポート: apikeylog（API キー管理、19 tests）。脆弱性診断: prefix 固定で全テーブルスキャン・rotate 非アトミック問題を修正。 (#736)

---

## [1.5.50] — 2026-05-21

### Added
- `docs/howto/job-queue.md` — バックグラウンドジョブキューガイド: 優先度キュー・リトライロジックはリポジトリ層・retry_count/max_retries・idempotency_key UNIQUE 制約・max_retries=0 で即失敗。 (#734)
- `docs/field-trials/2026-05-field-trial-116.md` — FT116 レポート: queuelog（ジョブキュー、27 tests）。 (#734)

---

## [1.5.49] — 2026-05-21

### Added
- `docs/howto/api-versioning.md` — API バージョニングガイド: URI プレフィックス方式・Deprecation/Sunset ヘッダー RFC 8594・toV1/toV2 変換・ストレージ共有設計。 (#732)
- `docs/field-trials/2026-05-field-trial-115.md` — FT115 レポート: versionlog（API バージョニング、14 tests）。 (#732)

---

## [1.5.48] — 2026-05-21

### Added
- `docs/howto/audit-trail.md` — 監査ログガイド: 監査はハンドラレイヤー・before/after スナップショット・immutable レコード・JWT クレームからアクター取得・ORDER BY id DESC。 (#730)
- `docs/field-trials/2026-05-field-trial-114.md` — FT114 レポート: auditlog（監査ログ、17 tests）。脆弱性診断: ダミーハッシュ不正形式・所有権チェック漏れを修正。 (#730)

---

## [1.5.47] — 2026-05-21

### Added
- `docs/howto/refresh-token-rotation.md` — Refresh Token Rotation ガイド: リフレッシュトークンのハッシュ保存（SHA-256、生値は DB に入れない）、ローテーション（使用後即 `revoke()`）、リプレイ攻撃検知（失効済みトークン再利用時に `revokeAllForUser()`）、ログアウトは必ず 204（情報漏洩防止）、`jti` クレームによるアクセストークンのユニーク性保証、アクセストークン（短命・stateless）とリフレッシュトークン（長命・DB管理）の分離。 (#728)
- `docs/field-trials/2026-05-field-trial-113.md` — FT113 レポート: refreshlog（JWT Refresh Token Rotation、15 tests / 63 assertions、6ペルソナ DX レビュー）。 (#728)

---

## [1.5.46] — 2026-05-21

### Added
- `docs/howto/multi-tenant-isolation.md` — マルチテナント隔離ガイド: 全クエリへの `tenant_id` フィルター必須（`ForTenant` サフィックスパターン）、JWT クレームへのテナント ID 埋め込みと `is_int()` 型チェック、クロステナントアクセスは 404（403 は情報漏洩）、レスポンスから `tenant_id` を除外、PHPStan `assertIsList()` による `list<>` 型保証、コードレビューチェックリスト。 (#726)
- `docs/field-trials/2026-05-field-trial-112.md` — FT112 レポート: tenantlog（マルチテナント隔離、13 tests / 61 assertions、6ペルソナ DX レビュー）。 (#726)

---

## [1.5.45] — 2026-05-21

### Added
- `docs/howto/rbac.md` — RBAC ガイド: JWT クレームへのロール埋め込み（DB 毎回参照とのトレードオフ）、`requireAuth()` / `requireRole()` ヘルパーパターン、401 vs 403 の正しい使い分け、`BearerTokenMiddleware` が HTTP メソッドを区別しない制限と手動検証回避策、`Role` enum / `tryFrom()` 型安全デコード、`createEmpty(204)` の正しい使い方。 (#724)
- `docs/field-trials/2026-05-field-trial-111.md` — FT111 レポート: rbaclog（RBAC、14 tests / 48 assertions、6ペルソナ DX レビュー）。 (#724)

---

## [1.5.44] — 2026-05-21

### Added
- `docs/howto/jwt-authentication.md` — JWT 認証ガイド: `LocalBearerTokenVerifier` が Issuer/Verifier を兼ねる、`BearerTokenMiddleware` の3モード（`excludedPaths` / `protectedPaths` / `protectedPathPrefixes`）、`nene2.auth.claims` からのクレーム取得、`exp` は int 必須（文字列は有効期限チェックがスキップされる）、`alg: none` 攻撃拒否の解説、JWT シークレット環境変数管理、トークンリボーク設計、コードレビューチェックリスト。 (#722)
- `docs/field-trials/2026-05-field-trial-110.md` — FT110 レポート: jwtlog（JWT 認証、14 tests / 53 assertions、6ペルソナ DX レビュー）。 (#722)

---

## [1.5.43] — 2026-05-21

### Added
- `docs/howto/password-hashing.md` — password hashing guide: `password_hash(PASSWORD_ARGON2ID)` vs `PASSWORD_DEFAULT` (bcrypt), `DatabaseConstraintException` for UNIQUE violation detection (not `\PDOException` — NENE2 wraps it), user enumeration prevention via dummy hash pattern (timing attack), `password_verify()` algorithm auto-detection (bcrypt ↔ Argon2id transparent), `password_needs_rehash()` for live migration, never returning `password_hash` in responses, `RouteRegistrar::register()` name conflict avoidance, code review checklist. (#720)
- `docs/field-trials/2026-05-field-trial-109.md` — FT109 report: pwdlog (password hashing, 14 tests, 39 assertions, 6-persona DX review). (#720)

---

## [1.5.42] — 2026-05-21

### Added
- `docs/howto/soft-delete.md` — soft delete guide: `deleted_at` schema pattern, critical `WHERE deleted_at IS NULL` filter (missing it leaks deleted data), `$includeTrashed = false` default, `purge()` guard (trash-first requirement), `insert()` vs `execute()` + `lastInsertId()`, REST semantics note, foreign key considerations, code review checklist. (#718)
- `docs/field-trials/2026-05-field-trial-108.md` — FT108 report: softdeletelog (soft delete, 18 tests, 30 assertions, 6-persona DX review). (#718)

---

## [1.5.41] — 2026-05-21

### Added
- `docs/howto/rate-limiting.md` — rate limiting guide: `ThrottleMiddleware` setup (`throttleMiddleware` named parameter), `X-RateLimit-*` headers, 429 response format, IP-based vs custom `keyExtractor` (user, API key), reverse proxy `REMOTE_ADDR` warning (`X-Forwarded-For` trust), `InMemoryRateLimitStorage` production restriction, Redis `RateLimitStorageInterface` implementation pattern, fixed-window burst problem, client retry pattern, code review checklist. (#716)
- `docs/field-trials/2026-05-field-trial-107.md` — FT107 report: throttlelog (rate limiting, 9 tests, 33 assertions, 6-persona DX review). (#716)

---

## [1.5.40] — 2026-05-21

### Added
- `docs/howto/etag-conditional-requests.md` — ETag & conditional requests guide: ETag generation (double-quote requirement, entity method pattern), `ConditionalGetHelper` for 304 Not Modified, `ConditionalWriteHelper` for 412/428 precondition checks, `If-Match: *` wildcard semantics, `Last-Modified` ISO 8601 format requirement, ETag vs version field comparison, code review checklist. (#714)
- `docs/field-trials/2026-05-field-trial-106.md` — FT106 report: etaglog (ETag/conditional requests, 15 tests, 37 assertions, 6-persona DX review). (#714)

---

## [1.5.39] — 2026-05-21

### Added
- `docs/howto/optimistic-locking.md` — optimistic locking guide: lost-update problem scenario, version field schema, `WHERE version = ?` pattern with `execute()` affected-rows check, 0-rows disambiguation (not-found vs conflict), atomic `version = version + 1` in SQL, 409 response with `current_version`, client retry flow, optimistic vs pessimistic comparison table, code review checklist. (#712)
- `docs/field-trials/2026-05-field-trial-105.md` — FT105 report: optlocklog (optimistic locking, 12 tests, 24 assertions, 6-persona DX review). (#712)

---

## [1.5.38] — 2026-05-21

### Added
- `docs/howto/webhook-signature.md` — Webhook HMAC-SHA256 signature verification guide: `hash_equals()` vs `===` timing attack explanation, Stripe-compatible header format, timestamp-in-payload design, replay attack prevention (5-minute window), secret rotation pattern, testing with `sign()` helper, code review checklist. (#710)
- `docs/field-trials/2026-05-field-trial-104.md` — FT104 report: hmaclog (Webhook signature verification, 13 tests, 23 assertions, 6-persona DX review). (#710)

---

## [1.5.37] — 2026-05-21

### Added
- `docs/howto/mass-assignment.md` — mass assignment defence guide: explicit DTO whitelist pattern, attack scenarios (role escalation, is_active tampering, id/timestamp forgery), response field control, trusted-internal-service DTO pattern, `create()` vs `createList()` distinction, code review checklist. (#708)
- `docs/field-trials/2026-05-field-trial-103.md` — FT103 report: masslog (mass assignment defence, 14 tests, 39 assertions, 6-persona DX review). (#708)

---

## [1.5.36] — 2026-05-21

### Added
- `docs/howto/transactions.md` — `DatabaseTransactionManagerInterface::transactional()` guide: correct callback-scoped executor pattern, injected-repository trap (silent rollback failure), pre-validation + atomic operation pattern, rollback testing, Laravel vs NENE2 connection model comparison. (#706)
- `docs/field-trials/2026-05-field-trial-102.md` — FT102 report: txlog (database transaction boundaries, rollback correctness, 6-persona DX review). (#706)

---

## [1.5.35] — 2026-05-21

### Added
- `docs/howto/nested-json-validation.md` — nested JSON validation guide: dot-notation error paths, collect-all-errors pattern, PHPStan discriminated-union workaround (`@var` annotation), `array_is_list()` usage, JSON numeric type handling. (#704)
- `docs/field-trials/2026-05-field-trial-101.md` — FT101 report: nestedlog (nested JSON validation with structured error paths). (#704)

---

## [1.5.34] — 2026-05-21

### Added
- `docs/howto/pagination.md` — OFFSET vs cursor pagination guide: fetch+1 pattern, limit clamping, performance comparison table, SQLite EXPLAIN QUERY PLAN note, migration verification technique. (#702)
- `docs/field-trials/2026-05-field-trial-100.md` — FT100 report: pagelog (OFFSET vs cursor pagination, 500-row deep-page correctness test). (#702)

---

## [1.5.33] — 2026-05-21

### Added
- `docs/howto/idempotency.md` — Idempotency-Key pattern guide: header validation, replay with 200, race condition handling via `DatabaseConstraintException` + UNIQUE constraint, key generation guidance. (#699)
- `docs/howto/csrf-and-json-api.md` — CORS vs CSRF guide: why CORS ≠ CSRF protection, JSON API preflight resistance, Bearer JWT immunity, SameSite cookies and Origin enforcement for cookie-based sessions. (#700)
- `docs/field-trials/2026-05-field-trial-99.md` — FT99 report: csrflog (CSRF-like patterns / idempotency key). (#699 #700)

---

## [1.5.32] — 2026-05-20

### Added
- `docs/howto/file-upload.md` — base64 JSON file upload guide: `requestMaxBodyBytes` sizing for base64 overhead, `finfo_buffer()` MIME detection, filename sanitization checklist (path traversal, null bytes, dangerous extensions), PHP exception property collision note. (#697)
- `docs/field-trials/2026-05-field-trial-98.md` — FT98 report: uploadlog (file upload security). (#697)

---

## [1.5.31] — 2026-05-20

### Added
- `Router::param(ServerRequestInterface $request, string $key): ?string` — static helper to read a single route path parameter without the two-step `getAttribute(Router::PARAMETERS_ATTRIBUTE)` boilerplate. (#693)
- `QueryStringParser::string()` — optional `$default` third argument; returns `$default` (instead of `null`) when the key is absent or empty. Backwards-compatible. (#692)
- `QueryStringParser::int()` — optional `$default` third argument; same pattern. (#692)
- `docs/howto/sql-injection.md` — SQL injection defense guide: LIKE parameterization, ORDER BY whitelist requirement, IN clause with variable-length placeholders. (#691)
- `docs/field-trials/2026-05-field-trial-97.md` — FT97 report: injectionlog (SQL injection defense). (#691)

---

## [1.5.30] — 2026-05-20

### Added
- `docs/howto/content-negotiation.md` — documents NENE2's JSON-only response design (no 406), `JsonRequestBodyParser` Content-Type behavior, and a custom middleware pattern for 406/415 enforcement. (#689)
- `docs/field-trials/2026-05-field-trial-96.md` — FT96 report: contentlog (content negotiation / Accept header). (#689)

---

## [1.5.29] — 2026-05-20

### Added
- `QueryStringParser::parse()` — returns `array<string, mixed>` (alias for `$request->getQueryParams()`). Mirrors the `JsonRequestBodyParser::parse()` discovery pattern so developers can find the method without knowing the typed-accessor API upfront. (#686)
- `docs/howto/handle-timezones.md` — timezone handling guide: always explicit UTC on `now`, IANA validation with `listIdentifiers()`, `createFromFormat` for strict parsing, DST ambiguous-time behavior (PHP takes first occurrence), local→UTC conversion pattern. (#687)
- `docs/field-trials/2026-05-field-trial-95.md` — FT95 report: schedulelog (timezone-sensitive scheduling). (#686 #687)
- `QueryStringParser` added to the public API stability guarantee (ADR 0009). (#686)

---

## [1.5.28] — 2026-05-20

### Added
- `docs/howto/use-bearer-auth.md` — Bearer token authentication guide: `BearerTokenMiddleware` setup (`authMiddleware` named parameter), `exp` claim recommendation, path protection modes, claims extraction, testing patterns, security property table. (#684)
- `docs/field-trials/2026-05-field-trial-94.md` — FT94 report: authlog (JWT Bearer authentication edge cases). (#684)

---

## [1.5.27] — 2026-05-20

### Changed
- `JsonResponseFactory::create()` and `createList()` now include `JSON_UNESCAPED_UNICODE` in the `json_encode` flags. Non-ASCII characters (Japanese, emoji, Arabic, etc.) are now emitted as literal UTF-8 in responses instead of `\uXXXX` escape sequences. Payload size for CJK-heavy responses is reduced by ~3×. (#681)

### Added
- `docs/howto/validate-unicode-input.md` — how-to guide for Unicode-aware input validation: `mb_strlen` vs `strlen`, null-byte rejection, grapheme clusters vs codepoints, and `JSON_UNESCAPED_UNICODE` behaviour. (#682)
- `docs/field-trials/2026-05-field-trial-93.md` — FT93 report: unicodelog (Unicode/emoji/encoding boundary). (#681 #682)

---

## [1.5.26] — 2026-05-20

### Added
- `docs/howto/prevent-double-booking.md` — reservation concurrency guide: distinguishing duplicate-user from over-capacity, TOCTOU window explanation, SQLite vs PostgreSQL behaviour, domain exception pattern. (#679)
- `docs/field-trials/2026-05-field-trial-92.md` — FT92 report: bookinglog (double-booking prevention). (#679)

---

## [1.5.25] — 2026-05-20

### Added
- `docs/howto/enforce-resource-ownership.md` — how-to guide for IDOR prevention: SQL-level ownership enforcement, 404-not-403 guidance, cross-tenant test patterns. (#677)
- `docs/field-trials/2026-05-field-trial-91.md` — FT91 report: noteslog (personal notes with IDOR prevention). (#677)

---

## [1.5.24] — 2026-05-20

### Added
- `ConditionalWriteHelper` — static helper for HTTP conditional writes. Evaluates the `If-Match` header and returns a `412 Precondition Failed` or `428 Precondition Required` problem-details response when the precondition fails; returns `null` when the write may proceed. Complements the existing `ConditionalGetHelper` (which handles `If-None-Match` / 304). (#674)
- `docs/howto/add-optimistic-locking.md` — how-to guide for implementing optimistic concurrency control with ETag / If-Match, including 428 usage, wildcard semantics, and conditional UPDATE pattern. (#674 #675)

---

## [1.5.23] — 2026-05-20

### Added
- `DatabaseConstraintException` — new exception class (extends `DatabaseConnectionException`) thrown by `PdoDatabaseQueryExecutor` when a DB constraint is violated (UNIQUE, FK, NOT NULL, CHECK). Catch this type to handle duplicate-key or FK violations without catching all connection errors. (#669)

### Changed
- `DatabaseConnectionException` — removed `final` modifier to allow `DatabaseConstraintException` to extend it. Existing `catch (DatabaseConnectionException)` blocks now also catch `DatabaseConstraintException` (Liskov-compatible). (#669)

---

## [1.5.22] — 2026-05-20

### Changed
- `Router`: static routes (zero path parameters) now always take priority over parameterized routes, regardless of registration order. Among routes with the same parameter count, registration order is preserved. This eliminates the common pitfall of `GET /resources/action` being captured by `GET /resources/{id}` when the parameterized route is registered first. (#649)

---

## [1.5.21] — 2026-05-20

### Added
- `JsonResponseFactory::createList()` — creates a bare JSON array response (`[{...}, ...]`). Accepts `list<mixed>` and encodes it as a top-level JSON array with `Content-Type: application/json`. Complements `create()` which is restricted to JSON objects (`array<string, mixed>`). (#645)

---

## [1.5.20] — 2026-05-20

### Added
- `ConditionalGetHelper` — static utility for ETag / conditional-GET support. `check($request, $responseFactory, $etag, $lastModified)` returns a 304 Not Modified response (with `ETag` and `Last-Modified` headers) when `If-None-Match` or `If-Modified-Since` indicates the client already has a fresh copy, or `null` when the handler should return the full 200 response. (#637)

---

## [1.5.19] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Path parameters" callout explaining `Router::PARAMETERS_ATTRIBUTE` extraction pattern; notes that `getAttribute('id')` returns `null` and causes silent 404s. (#624)
- `docs/field-trials/2026-05-field-trial-48.md` — FT48 (webhooklog) field trial report.

---

## [1.5.18] — 2026-05-20

### Added
- `QueryStringParser::array()` — typed helper for PHP-style repeated query parameters (`?key[]=v1&key[]=v2`). Returns `list<string>|null`, trimming each value and filtering empty strings. (#621)
- `docs/howto/add-pagination.md` — documented `QueryStringParser::array()` in Step 6 alongside `commaSeparated()`. (#621)
- `docs/howto/add-database-endpoint.md` — noted that `CAST(? AS INTEGER)` is required in `HAVING` clauses with `COUNT(DISTINCT ...)` comparisons (PDO binds all array values as strings). (#622)
- `docs/field-trials/2026-05-field-trial-47.md` — FT47 (tagfilterlog) field trial report.

---

## [1.5.17] — 2026-05-20

### Fixed
- `JsonResponseFactory::create()` now includes `JSON_PRESERVE_ZERO_FRACTION` so whole-number PHP floats (e.g. `12.0`) are encoded as JSON numbers with a decimal point (`12.0`) rather than integers (`12`). (#602)

### Added
- `docs/howto/quality-tools.md` — new guide covering PHPStan and PHP-CS-Fixer configuration, including the PHPStan 2.x `--memory-limit` CLI-flag requirement. (#603)
- `docs/field-trials/2026-05-field-trial-33.md` — FT33 (shiftlog) field trial report.

---

## [1.5.16] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Aggregate queries with HAVING and PDO parameters (SQLite)" section: explains why `HAVING col <= ?` performs text comparison and documents the `CAST(? AS INTEGER)` fix. (#600)
- `docs/field-trials/2026-05-field-trial-32.md` — FT32 (inventorylog) field trial report.

---

## [1.5.15] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Full-text search with SQLite FTS5" section: content table setup, sync triggers (AFTER INSERT/DELETE/UPDATE), JOIN + MATCH query pattern, prefix matching. (#598)
- `docs/field-trials/2026-05-field-trial-31.md` — FT31 (feedlog) field trial report.

---

## [1.5.14] — 2026-05-20

### Fixed
- `PdoConnectionFactory` now runs `PRAGMA foreign_keys = ON` immediately after creating a SQLite connection. Previously, `ON DELETE CASCADE` and other foreign key constraints were silently ignored on SQLite. (#596)

### Added
- `docs/field-trials/2026-05-field-trial-30.md` — FT30 (projtrack) field trial report.

---

## [1.5.13] — 2026-05-20

### Added
- `DatabaseQueryExecutorInterface::insert()` — convenience method that executes an INSERT and returns the auto-generated ID in one call (wraps `execute()` + `lastInsertId()`). (#593)
- `JsonResponseFactory::createEmpty()` — creates a bodyless response for 204 No Content and similar status codes. (#594)
- `docs/howto/add-database-endpoint.md` — added "Inserting a row and retrieving the generated ID" section documenting `insert()` and the two-step `execute()` + `lastInsertId()` pattern. (#593)
- `docs/howto/add-custom-route.md` — added "Returning 204 No Content" section documenting `createEmpty()` for DELETE endpoints. (#594)

---

## [1.5.12] — 2026-05-20

### Added
- `docs/howto/implement-bulk-endpoint.md` — new howto covering the complete bulk create pattern: envelope validation, per-item validation with `"scores[{i}].field"` indexed error names, size limiting, route registration order, and transaction note. (#591)

---

## [1.5.11] — 2026-05-20

### Changed
- `docs/howto/add-custom-route.md` — added "Action endpoints" section documenting the `POST /resource/{id}/action` pattern (archive, restore, publish, approve) with registration-order note; updated HTTP methods table to include PATCH. (#589)

---

## [1.5.10] — 2026-05-20

### Changed
- `docs/howto/add-custom-route.md` — added warning: static route segments must be registered before parameterised routes sharing the same prefix. Includes example of the silent misbehaviour and the correct ordering. (#586)
- `docs/howto/implement-patch-endpoint.md` — added Step 4 "Validating PATCH fields": explains `array_key_exists` vs `isset` for PATCH field detection, validation pattern for optional fields, and nullable repository parameter pattern. (#587)

---

## [1.5.9] — 2026-05-20

### Added
- `QueryStringParser::commaSeparated()` — splits a comma-separated query parameter into a `list<string>`, trimming whitespace and removing empty values. Returns `null` when the key is absent or produces an empty list. (#584)
- `docs/howto/add-pagination.md` — added Step 6 documenting `QueryStringParser` helpers (`string`, `int`, `bool`, `commaSeparated`) with usage examples. (#584)

---

## [1.5.8] — 2026-05-20

### Changed
- `CorsMiddleware` — added PHPDoc note that OPTIONS preflight requests receive a `204 No Content` response and do not reach the route handler. (#582)

---

## [1.5.7] — 2026-05-20

### Changed
- `DatabaseTransactionManagerInterface::transactional()` — added PHPDoc warning: repositories injected at construction time must not be reused inside the callback; they execute on a different connection outside the transaction. (#579)
- `DatabaseConfig` — added PHPDoc noting that for SQLite only `adapter` and `name` are required; `host`, `user`, `password`, `charset` accept empty strings. (#580)
- `docs/howto/use-transactions.md` — added prominent `> **Warning**` callout about the injected-repository anti-pattern; expanded "Test with a file-based SQLite database" section with `DatabaseConfig` + `PdoConnectionFactory` setup, `:memory:` limitation explanation, and notes on SQLite optional fields. (#578 #579 #580)

---

## [1.5.6] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Handling UNIQUE constraint violations" section: `DatabaseConnectionException` wraps `PDOException`; catch `DatabaseConnectionException` and inspect `getPrevious()` to detect constraint violations. Includes per-adapter message fragments (SQLite / MySQL / PostgreSQL). (#576)

### Changed
- `InMemoryRateLimitStorage` — removed `@internal` annotation; promoted to public API. The class is for local development and single-process testing; PHPDoc now explicitly documents that it is NOT shared across PHP-FPM workers. Consumers can now use it in tests without PHPStan warnings. (#574)
- `docs/howto/add-health-check.md` — fixed `check()` return type from `bool` to `HealthStatus` in the Quick Start example; added inline note that `name()` return value becomes the key in the `checks` response map. (#575)

---

## [1.5.5] — 2026-05-20

### Added
- `QueryStringParser` (`Nene2\Http\QueryStringParser`) — typed helpers for extracting individual query-string parameters from a PSR-7 request: `string()`, `int()`, and `bool()`. Complements `PaginationQueryParser` (which only handles `limit`/`offset`) with ergonomic accessors for custom filters such as `?category=tech` or `?is_read=false`. `bool()` treats `"0"`, `"false"`, and `"no"` as false; absent or empty-string values return `null`. (#570)
- `docs/howto/implement-patch-endpoint.md` — step-by-step guide for PATCH endpoints: `array_key_exists()` vs `isset()` for partial update field extraction, the empty-body `new stdClass()` pattern, repository contract with empty-fields no-op, and a PHPUnit test helper example. (#571)

### Changed
- `JsonRequestBodyParser::parse()` — when the body is a JSON array instead of a JSON object, the error message now includes an actionable hint: `Hint: in PHP, json_encode([]) produces "[]" (a JSON array). Use json_encode((object)[]) or new stdClass() to produce "{}" (a JSON object).` (#572)

---

## [1.5.4] — 2026-05-20

### Added
- `docs/howto/use-postgresql.md` — complete PostgreSQL setup guide: Dockerfile (`pdo_pgsql`), Docker compose (`postgres:17`), Phinx config, `RETURNING id` pattern for INSERT ID retrieval, per-adapter test data reset (`TRUNCATE RESTART IDENTITY CASCADE` vs MySQL/SQLite), and `DB_CHARSET` behavior on `pgsql`. (#562 #563 #564 #565)
- `docs/howto/add-domain-exception-handler.md` — step-by-step guide for implementing `DomainExceptionHandlerInterface`: correct `ProblemDetailsResponseFactory::create()` call order (`$request` first, slug-only `type`), common mistakes (missing request, full URL in type), and wiring via `RuntimeApplicationFactory::$domainExceptionHandlers`. (#563)

### Changed
- `DatabaseQueryExecutorInterface::lastInsertId()` — added PHPDoc explaining that the method returns `0` on PostgreSQL and documenting the `fetchOne()` + `RETURNING id` alternative. (#562)
- `PdoConnectionFactory` — added inline comment noting that `DB_CHARSET` is intentionally excluded from the `pgsql` DSN; documents the `DATABASE_URL` workaround for explicit client encoding. (#565)

---

## [1.5.3] — 2026-05-20

### Added
- `RequestScopedHolder<T>` (`Nene2\Http\RequestScopedHolder`) — a generic mutable holder for request-scoped values. Inject one shared instance into a middleware (writer) and a route handler or repository (reader) to pass extracted values — tenant ID, decoded claims, trace context — without threading the PSR-7 request object through every layer. Includes `set()`, `get()`, `isSet()`, and `reset()` (for async runtimes). (#555)
- `RuntimeApplicationFactory::$authMiddleware` now accepts `list<MiddlewareInterface>` in addition to a single `MiddlewareInterface|null`. Pass a list to stack multiple middlewares in sequence (first item runs first) without wrapping them in a composite — e.g. a tenant extractor followed by a JWT verifier. Existing single-middleware callers are unaffected. (#556)
- `docs/howto/request-scoped-state.md` — explains the holder pattern, when to prefer it over PSR-7 request attributes, how to stack multiple auth middlewares, and the async-runtime caveat (PHP shared-nothing model). (#557)

---

## [1.5.2] — 2026-05-20

### Added
- `RuntimeApplicationFactory::$requestMaxBodyBytes` — configures the request body size limit passed to `RequestSizeLimitMiddleware`. Defaults to 1 MiB (1 048 576 bytes). Increase for bulk-import or large-payload endpoints. (#547)
- `docs/howto/use-transactions.md` — explains the transactional repository pattern: why `transactional()` provides a fresh executor, how to instantiate concrete repositories inside the callback, how to test with a file-based SQLite, and rollback verification. (#549)

### Fixed
- `ApiKeyAuthenticationMiddleware`: `$protectedPaths` and `$protectedPathPrefixes` are now evaluated in **union mode** — a path is protected when it matches any exact entry in `$protectedPaths` OR starts with any prefix in `$protectedPathPrefixes`. Previously, a non-empty `$protectedPaths` suppressed all prefix evaluation, causing `$protectedPathPrefixes` to be silently ignored when both were set. (#548)

---

## [1.5.1] — 2026-05-20

### Added
- `RuntimeApplicationFactory::$machineApiKeyProtectedPathPrefixes` — exposes `ApiKeyAuthenticationMiddleware::$protectedPathPrefixes` through the factory; protects paths starting with any listed prefix without enumerating each route individually (#540)
- `RuntimeApplicationFactory::$machineApiKeyProtectedMethods` — exposes `ApiKeyAuthenticationMiddleware::$protectedMethods` through the factory; enables "public read / key-gated write" patterns (e.g. GET is open, POST/PUT/DELETE require the API key) without manual middleware construction (#540)
- `docs/development/quality-tools.md`: "PHP-CS-Fixer Risky Fixers in Consumer Projects" section — explains `setRiskyAllowed(true)` and `--allow-risky=yes` required when using `declare_strict_types` and other risky rules in consumer projects (#542)

---

## [1.5.0] — 2026-05-20

### Added
- `ApiKeyAuthenticationMiddleware::$protectedPathPrefixes` — prefix allowlist parameter; protects paths starting with any listed prefix (e.g. `/admin/` matches `/admin/users/42`). Evaluated only when `$protectedPaths` is empty, mirroring `BearerTokenMiddleware` behaviour. (#461, #482)
- `ApiKeyAuthenticationMiddleware::$protectedMethods` — method filter; when non-empty, only requests whose HTTP method is in the list are protected. Enables "GET is public, POST/DELETE require API key" patterns without a custom middleware. (#461, #482)
- `ExampleServiceProvider` (`Nene2\Example`) — bundles Note and Tag example services and exposes `ROUTE_REGISTRARS` / `EXCEPTION_HANDLERS` string keys so framework infrastructure code can wire example routes without importing individual example classes (#463)
- `LocalMcpToolCatalog` — `is_file()` guard before `file_get_contents()` to avoid PHP warnings on missing catalog files
- `docs/adr/0009`: `nene2.auth.credential_type` and `nene2.auth.claims` request attributes documented as part of the stable public API (#462)
- `docs/howto/add-html-view.md` — new howto: server-rendered HTML with `NativePhpViewRenderer` and `HtmlResponseFactory` (#487)
- `docs/howto/add-mcp-tools.md` — new howto: MCP tool catalog setup, read/write tools, JWT protection, MCP server startup, Claude Code/Desktop config (#489)
- `docs/howto/add-database-endpoint.md`: M:N many-to-many relationship section — join table schema, idempotent `attachTag`/`detachTag` repository pattern, MySQL `INSERT IGNORE` note (#457)
- `docs/howto/add-custom-route.md`: "Reserved framework paths" section — lists built-in paths (`/`, `/health`, etc.) that cannot be overridden by user route registrars (#493)
- `docs/development/quality-tools.md`: PHPStan `memory_limit` guidance updated — `memory_limit` in `phpstan.neon` is not supported in PHPStan 2.x; use `--memory-limit` CLI flag instead (#469, #493)

### Improved
- `tests/View/NativePhpViewRendererTest`: 5 → 13 tests — added boundary cases for `HtmlEscaper` (null, numeric types, empty string, multibyte UTF-8), `HtmlResponseFactory` (default status, multiple headers), and `NativePhpViewRenderer` (exception buffer cleanup, subdirectory resolution) (#486)
- `tests/Mcp/LocalMcpToolCatalogTest`: 3 → 15 tests — added error cases (file not found, invalid JSON, missing keys), safety level variants, method uppercasing, and `responseSchemaRef` handling (#489)

### Field Trials (FT15 · FT16)
- **FT15** — noteboard (HTML View): `NativePhpViewRenderer` + `HtmlResponseFactory` — 7 endpoints, 10/10 tests, PHPStan level 8, PHP-CS-Fixer clean. Frictions: F-1 `GET /` reserved (→ add-custom-route.md), F-2 `Router::PARAMETERS_ATTRIBUTE` usage, F-3 PHPStan neon `memory_limit` (→ quality-tools.md)
- **FT16** — noteboard MCP: 3 MCP tools (2 read + 1 write) added to FT15 app. `composer mcp --root=.` validates cleanly with v1.4.2+. No friction — `add-mcp-tools.md` howto works as written.

---

## [1.4.2] — 2026-05-19

### Added
- `validate-mcp-tools.php` — `--root=<path>` CLI option; falls back to `getcwd()` so consumer projects can run the validator without a wrapper script (#459)
- `composer.json` `suggest` entry for `symfony/yaml` — consumer projects are guided to add it when using the MCP validator (#460)
- CLAUDE.md section on how to wire the MCP validator in a consumer project
- `BearerTokenMiddleware::$protectedPathPrefixes` — prefix allowlist parameter; protects all paths starting with the listed prefixes (e.g. `/me/` matches `/me/favorites/42`); evaluated after `$protectedPaths` and before `$excludedPaths` (#467)
- `CompositeAuthMiddleware` — chains multiple `MiddlewareInterface` instances as a single auth middleware; enables three-tier access models (public / Bearer / API key) by composing path-scoped middlewares in order (#466, #481)

### Changed
- `LocalBearerTokenVerifier` — removed `@internal` tag; now part of the public API stability guarantee (see ADR 0009) (#468)

---

## [1.4.1] — 2026-05-19

### Added
- `FrameworkInfo::VERSION` — public string constant exposing the current framework version (`Nene2\FrameworkInfo`) (#417)
- `BearerTokenMiddleware::$excludedPaths` — blocklist parameter to skip authentication on specific paths (e.g. `/auth/register`, `/auth/login`); complements the existing `$protectedPaths` allowlist (#440)
- `TokenIssuerInterface` — public stable interface for JWT issuance (`Nene2\Auth`); `LocalBearerTokenVerifier` now implements it (#442)
- `add-jwt-authentication` how-to guide in 6 languages (#449)

### Changed
- `RuntimeApplicationFactory` — `$bearerTokenMiddleware: ?BearerTokenMiddleware` parameter renamed to `$authMiddleware: ?MiddlewareInterface`; accepts any PSR-15 middleware, not only `BearerTokenMiddleware` (#441)

### Fixed
- `add-jwt-authentication.md` — corrected `DomainExceptionHandlerInterface` method name (`handles` → `supports`), `handle()` signature (2 params, not 3), request body parsing (`JsonRequestBodyParser::parse()` instead of `getParsedBody()`), and handler return type note (FT12-A findings F-3/F-4/F-5)

---

## [1.4.0] — 2026-05-18

### Added
- `AppConfig::$problemDetailsBaseUrl` — configurable base URL for Problem Details `type` URIs (`Nene2\Config`); defaults to `https://nene2.dev/problems/` (#409)
- `PROBLEM_DETAILS_BASE_URL` environment variable — override the base URL per project without touching framework code (#409)
- `.env.example` entry for `PROBLEM_DETAILS_BASE_URL` (commented out) (#409)

### Changed
- `ProblemDetailsResponseFactory` — accepts `string $problemDetailsBaseUrl` as a third constructor parameter; existing call sites that omit it continue to use the `nene2.dev` default (#409)
- `RuntimeServiceProvider` — wires `AppConfig::$problemDetailsBaseUrl` into `ProblemDetailsResponseFactory` (#409)

---

## [1.3.0] — 2026-05-18

### Added
- `PaginationQuery` readonly DTO — holds validated `limit` and `offset` values (`Nene2\Http`) (#360)
- `PaginationQueryParser::parse()` — parses and validates `?limit=&offset=` query parameters; throws `ValidationException` on out-of-range values; accepts `$defaultLimit` and `$maxLimit` parameters (`Nene2\Http`) (#360)
- `tools/openapi-to-md.php` — generates `docs/reference/http-endpoints.md` from `docs/openapi/openapi.yaml`; runnable via `composer openapi:docs` (#362)
- `InvalidJson` and `TooManyRequests` response components added to `openapi.yaml`; POST/PUT endpoints now reference the 400 `InvalidJson` response (#362)
- `public_html/problems/invalid-json/` and `public_html/problems/too-many-requests/` human-readable Problem Details type pages (#362)

### Changed
- `ListNotesHandler` and `ListTagsHandler` — replaced duplicated limit/offset parsing logic with `PaginationQueryParser::parse()` (#360)

---

## [1.2.0] — 2026-05-18

### Added
- `JsonRequestBodyParser` and `JsonBodyParseException` — parse request body as JSON object; throws on empty body, invalid JSON, or non-object JSON (`Nene2\Http`) (#354)
- `ErrorHandlerMiddleware` maps `JsonBodyParseException` → `400 invalid-json` Problem Details, distinct from `422 validation-failed` (#354)

### Changed
- `CreateNoteHandler`, `UpdateNoteHandler`, `CreateTagHandler`, `UpdateTagHandler` — replaced `(array) json_decode(...)` with `JsonRequestBodyParser::parse()` (#354)

---

## [1.1.0] — 2026-05-18

### Added
- `HealthCheckInterface` and `HealthStatus` — stable public interface for dependency health checks (`Nene2\Http`) (#346)
- `RuntimeApplicationFactory` extended with `list<HealthCheckInterface> $healthChecks = []`; `GET /health` returns `checks` map and 503 when any check reports `error` (#346)
- `DatabaseHealthCheck` reference implementation in `src/Example/Health/` (#346)
- OpenAPI `HealthResponse` schema extended with optional `checks` field; `503` response added to `/health` path (#346)
- ADR 0010: rate limiting design — fixed window, IP-keyed by default, `RateLimitStorageInterface` as storage abstraction (#348)
- `RateLimitStorageInterface` — stable public interface for rate limit counter storage (`Nene2\Middleware`) (#348)
- `InMemoryRateLimitStorage` (`@internal`) — fixed-window in-memory storage for local development and testing (#348)
- `ThrottleMiddleware` — PSR-15 middleware; 429 Problem Details + `Retry-After` + `X-RateLimit-*` headers; position 8 after Auth (#348)
- `RuntimeApplicationFactory` extended with optional `ThrottleMiddleware $throttleMiddleware = null` (#348)

---

## [1.0.0] — 2026-05-18

### Added
- ADR 0009 v1.0 readiness: `ResponseEmitter` added to stable surface; `HtmlResponseFactory` and `TemplateNotFoundException` moved to correct `Nene2\View` namespace in stable list (#340)
- PHPDoc on stable public interfaces: `ServiceProviderInterface`, `DomainExceptionHandlerInterface`, `DatabaseConnectionFactoryInterface`, `ResponseEmitter` (#340)
- `docs/milestones/2026-05-v1.0.md` — v1.0 tagging criteria documented (#340)

### Changed
- Public API stability contract in effect: surfaces listed in ADR 0009 will not have breaking changes without a major version bump
- `src/Example/` explicitly outside the stability guarantee (reference implementation)

---

## [0.8.0] — 2026-05-18

### Added
- ADR 0009: v1.0 public API scope and stability guarantee — `src/Example/` declared as reference implementation outside the stability promise (#334)
- `@internal` annotations on implementation-detail classes: `ConfigLoader`, `PdoConnectionFactory`, `PdoDatabaseQueryExecutor`, `PdoDatabaseTransactionManager`, `RuntimeServiceProvider`, `RuntimeContainerFactory`, `MonologLoggerFactory`, `RequestIdHolder`, `RequestIdProcessor`, `LocalMcpToolCatalog`, `LocalBearerTokenVerifier`, `NativeLocalMcpHttpClient`, `LocalMcpHttpResponse` (#334 #337)
- `PdoTagRepositoryMySqlTest` — MySQL integration tests for Tag repository (save / findAll / update / delete, 7 cases) (#312)
- Frontend Tag support: `frontend/src/api/tags.ts` typed fetch wrapper, `TagList` and `TagForm` React components with live API integration (#314)
- Frontend component tests for `TagList` and `TagForm` (#321)
- Frontend API type-guard tests for `tags.ts` (#321)

### Changed
- README: `src/Example/` documented as reference implementation not covered by the stability guarantee (#334)
- CLAUDE.md § 5 middleware order corrected to match `RuntimeApplicationFactory` implementation: `RequestId → Logging → Security → CORS → Error → RequestSize → Auth` (#337)
- ADR 0008 middleware placement table corrected to match implementation (#337)
- Roadmap: Phase 38–39–40–41 entries added (#334 #337)

---

## [0.7.0] — 2026-05-18

### Added
- `PUT /examples/tags/{id}` — full tag update endpoint; replaces `name` field (#304)
- `DELETE /examples/tags/{id}` — tag delete endpoint; returns 204 (#304)
- `UpdateTagUseCase`, `UpdateTagHandler`, `UpdateTagInput/Output`, `UpdateTagUseCaseInterface` (#304)
- `DeleteTagUseCase`, `DeleteTagHandler`, `DeleteTagByIdInput`, `DeleteTagUseCaseInterface` (#304)
- `TagRepositoryInterface::update(Tag): void` and `delete(int): void` implemented in `PdoTagRepository` and `InMemoryTagRepository` (#304)
- 5 Tag MCP tools in `docs/mcp/tools.json`: `listExampleTags`, `getExampleTagById`, `createExampleTag`, `updateExampleTagById`, `deleteExampleTagById` — Tag/Note MCP parity restored (#304)
- `database/migrations/20260516000001_create_tags_table.php` — Phinx migration for the `tags` table (#306)
- `database/schema/tags.sql` — schema snapshot for the `tags` table (#306)
- Endpoint scaffold checklist: added "create migration" step for new entity tables (#307)

### Changed
- `TagRouteRegistrar` extended from 3 to 5 routes and constructor params (#304)
- `TagServiceProvider` registers `UpdateTagHandler`, `DeleteTagHandler`, and their use cases; registrar closure updated (#304)
- `docs/howto/add-second-entity.md` — Tag endpoint table corrected to reflect full CRUD

---

## [0.6.0] — 2026-05-17

### Added
- `src/Example/Note/NoteRouteRegistrar` — registers Note routes via `__invoke(Router): void`; used by `NoteServiceProvider` and directly in tests (#299)
- `src/Example/Tag/TagRouteRegistrar` — registers Tag routes via `__invoke(Router): void`; used by `TagServiceProvider` and directly in tests (#299)
- `LocalMcpHttpClientInterface::hasAuthentication(): bool` — write tools are now gated: calling a `safety: write` tool without `NENE2_LOCAL_JWT_SECRET` set returns a `LocalMcpException` with a clear setup message (#301)
- `docs/howto/add-second-entity.md` — HOWTO for adding a second domain entity using the RouteRegistrar pattern, in 6 languages (en/ja/fr/zh/pt-br/de) (#303)
- VitePress HOWTO sidebar updated with "Add a second entity" entry in all 6 locales (#303)

### Changed
- `RuntimeApplicationFactory` constructor reduced from 16 parameters to 8 — entity-specific handler parameters removed; all entity routes are now contributed via the `$routeRegistrars` parameter (#299)
- `NoteServiceProvider` / `TagServiceProvider` each register a `nene2.route_registrar.*` service; `RuntimeServiceProvider` references registrars by key instead of passing individual handlers (#299)
- `NativeLocalMcpHttpClient` implements `hasAuthentication()` based on bearer token presence (#301)
- `tools/local-mcp-server.php` token scope updated from `read:system` to `read:system write:example` (#301)
- VitePress version badge updated to `v0.6.0` (#303)

---

## [0.5.0] — 2026-05-17

### Added
- Diátaxis Explanation pages in 6 languages (English, Japanese, French, Chinese, Portuguese, German): `why-psr`, `why-explicit-wiring`, `why-problem-details`, `why-mcp` (#275)
- Second domain entity example: `src/Example/Tag/` with full CRUD (#277)
  - `GET /examples/tags`, `POST /examples/tags`, `GET /examples/tags/{id}`
  - `ListTagsUseCase`, `GetTagByIdUseCase`, `CreateTagUseCase` with readonly DTOs
  - `PdoTagRepository`, `InMemoryTagRepository`, `TagServiceProvider`
  - OpenAPI schemas and 8 HTTP-level tests
- Production deployment guide (`docs/howto/deploy-production.md`) with multi-stage `Dockerfile.prod`, env management, Nginx/Caddy reverse proxy examples, security checklist, and post-deploy verification in 6 languages (#279)
- Frontend starter content: `NoteList` and `NoteForm` React components with live API integration (#281)
  - Typed fetch wrapper `frontend/src/api/notes.ts` for all Note endpoints
  - `LoadState` union type pattern for loading / error / ok states
  - `App.tsx` wired to refresh the list on note creation
- `src/Auth/BearerTokenMiddleware` — PSR-15 middleware for `Authorization: Bearer` tokens; returns RFC 6750-compliant 401 Problem Details on failure (#283)
- `src/Auth/TokenVerifierInterface` — pluggable token verifier; framework ships no concrete JWT library dependency (#283)
- `src/Auth/TokenVerificationException` — thrown by verifier implementations on any failure (#283)
- ADR 0008: JWT authentication direction — library-agnostic design, `firebase/php-jwt` as recommended starting point (#283)
- VitePress 1.6.4 multilingual documentation site with 6 locales, Explanation nav/sidebar, and production deployment sidebar entry (#275)

### Changed
- CI `backend.yml`: explicit PHP extensions (`pdo`, `pdo_sqlite`, `pdo_mysql`) and Composer dependency cache (#273)
- CI `docs.yml`: `cache-dependency-path: package-lock.json` added to setup-node step (#273)
- `docs/development/authentication-boundary.md`: JWT section with `firebase/php-jwt` wiring example, request attribute table, and failure response specification (#283)
- `docs/adr/index.md`: ADR 0008 entry added (#283)

---

## [0.4.0] — 2026-05-17

### Added
- `RuntimeApplicationFactory` accepts `$routeRegistrars` — an optional `list<callable(Router): void>` for injecting custom routes without subclassing (#236)
- Local MCP server now executes write operations (`POST`, `PUT`, `DELETE`) through the documented API boundary (#228)
  - `LocalMcpHttpClientInterface` extended with `post()`, `put()`, `delete()` methods
  - `NativeLocalMcpHttpClient` implements the new methods with JSON body support
  - `LocalMcpServer` routes tools to the correct HTTP method; path parameters are interpolated from arguments; GET query arguments are appended as a query string
  - `LocalMcpToolCatalog` now exposes all tools (not only `read`); `responseSchemaRef` is nullable

---

## [0.3.0] — 2026-05-17

### Added
- Monolog `RequestIdProcessor`: attaches `X-Request-Id` as `extra.request_id` to every log record (#216)
  - `RequestIdHolder` (mutable singleton) — middleware writes the ID, processor reads it
  - `RequestIdMiddleware` accepts optional `RequestIdHolder` injection
- ADR 0007: PUT vs PATCH policy for resource update operations (#218)
- v0.3.0 readiness milestone with Packagist publication criteria review (#222)

### Changed
- README: added Domain Layer Example section linking `src/Example/Note/` as canonical reference (#217)
- README: Repository Layout updated with `src/Log/` and `src/Example/Note/`

---

## [0.2.0] — 2026-05-17

### Added
- `PUT /examples/notes/{id}` update endpoint — completes full Note CRUD (#212)
  - `UpdateNoteUseCase`, `UpdateNoteHandler`, `UpdateNoteInput/Output`, `UpdateNoteUseCaseInterface`
  - `NoteRepositoryInterface::update(Note): void` + PDO and in-memory implementations
  - `Router::put()` method
  - OpenAPI path with 200/404/422/500 responses
  - HTTP-level and use-case unit tests

---

## [0.1.3] — 2026-05-17

### Added
- Monolog 3.x structured JSON logging to stderr (`src/Log/MonologLoggerFactory`) (#206)
  - Log level driven by `APP_DEBUG`: `debug` when true, `warning` otherwise
  - ADR 0005 documents the decision
- `GET /examples/notes` collection endpoint with `limit`/`offset` pagination (#204)
  - `ListNotesUseCase`, `ListNotesHandler`, `ListNotesInput/Output/Item`
  - OpenAPI schema `ExampleNoteListResponse` with `items`, `limit`, `offset`
- HTTP-level integration tests for all Note endpoints (#203)
  - Uses `InMemoryNoteRepository` + full middleware stack
  - Covers GET/POST/DELETE success + error paths, collection pagination
- Error handler systemization: `DomainExceptionHandlerInterface` pluggable mapping (#197)
  - `NoteNotFoundExceptionHandler` maps `NoteNotFoundException` → 404 Problem Details
  - Domain handlers + middleware decouple exception handling from individual route handlers
- MySQL Docker Compose verification tests (#194)
- Setup guide (`docs/development/setup.md`) and MySQL Docker section (#196)
- `.env` copy step added to README Quick Start (#192)

### Changed
- `NoteRepositoryInterface` extended with `findAll(int $limit, int $offset): list<Note>` (#204)
- `GetNoteByIdHandler` and `DeleteNoteHandler` simplified (no longer inject `ProblemDetailsResponseFactory`) (#197)
- DB env vars (`DB_HOST`, `DB_PORT`, etc.) added to `compose.yaml` `app` service defaults (#194)

---

## [0.1.2] — 2026-05-14

### Added
- Note CRUD end-to-end: `POST /examples/notes`, `DELETE /examples/notes/{id}` (#190)
  - `CreateNoteUseCase`, `DeleteNoteUseCase`, readonly DTOs
  - OpenAPI paths for POST + DELETE with Problem Details schemas
- Domain layer policy documents and Phase 9 milestone (#182, #180)
- Local MCP smoke helper script (`tools/mcp-smoke.php`) (#178)
- Cursor/GitHub MCP authentication notes (#168)
- MCP integer path-parameter requirement documented (#167)

### Changed
- `NoteRepositoryInterface` extended with `save(Note): int` and `delete(int): void`
- `PdoNoteRepository` implements save (INSERT + lastInsertId) and delete (DELETE WHERE id)

---

## [0.1.1] — 2026-05-10

### Added
- API-key authentication middleware (`X-NENE2-API-Key` header) (#140)
- Local MCP server (`tools/local-mcp-server.php`) with read-only Note tools (#138)
- LLM delivery starter documentation and client project start guide (#134, #150)
- Machine-client smoke workflow and local MCP client config example (#154, #152)
- MySQL Docker Compose service and migration runner (#144)
- Endpoint scaffold workflow documentation (#142)
- v0.1.x patch release policy (#146)

---

## [0.1.0] — 2026-05-07

### Added
- PHP 8.4 HTTP runtime: PSR-7/15/17 via Nyholm + FastRoute (#43)
- PSR-11 DI container with explicit wiring and service providers (#43)
- RFC 9457 Problem Details error responses (`application/problem+json`) (#43)
- `GET /examples/notes/{id}` — first domain endpoint with use case pattern (#43)
- PHPUnit integration tests, PHPStan level 8, PHP-CS-Fixer (#43)
- OpenAPI contract (`docs/openapi/openapi.yaml`) + Swagger UI (#9)
- Docker Compose environment with MySQL service (#7)
- Phinx migration runner with `database/` layout (#13)
- PSR-3 logging boundary (NullLogger placeholder at this release)
- Governance docs: workflow, coding standards, ADR policy, review checklists (#1)
- ADR 0001–0004: HTTP runtime, DI container, phpdotenv, Phinx

[Unreleased]: https://github.com/hideyukiMORI/NENE2/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.2...v1.5.0
[1.4.2]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.8.0...v1.0.0
[0.8.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/hideyukiMORI/NENE2/releases/tag/v0.1.0
