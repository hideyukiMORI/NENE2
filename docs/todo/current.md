# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.5.105`（2026-05-26 リリース済み）
- Current branch: `main` — clean — FT172 PR 作成前（#872 vite Dependabot リベース待ち）

## Recently Completed (FT ループ — FT96–FT170 / v1.5.30–v1.5.104)

| FT | テーマ | アプリ | テスト | リリース | 主要対応 |
|---|---|---|---|---|---|
| FT96 | コンテントネゴシエーション | contentlog | 16/16 | v1.5.30 | content-negotiation.md（406 を返さない設計を明文化） |
| FT97 | SQL インジェクション防御 | injectionlog | 19/19 | v1.5.31 | `Router::param()`・`QueryStringParser` デフォルト値引数・sql-injection.md |
| FT98 | ファイルアップロード | uploadlog | 19/19 | v1.5.32 | file-upload.md（base64 オーバーヘッド・MIME 検出・パストラバーサル） |
| FT99 | CSRF 的パターン・冪等性 | csrflog | 15/15 | v1.5.33 | idempotency.md・csrf-and-json-api.md（CORS ≠ CSRF 明文化） |
| FT100 | OFFSET vs カーソルページネーション | pagelog | 15/15 | v1.5.34 | pagination.md（fetch+1・パフォーマンス比較・選択基準） |
| FT101 | ネスト JSON バリデーション | nestedlog | 19/19 | v1.5.35 | nested-json-validation.md（ドット記法・全収集・PHPStan 判別共用体） |
| FT102 | DBトランザクション境界 | txlog | 11/11 | v1.5.36 | transactions.md（コールバック外インジェクト罠・pre-validation パターン） |
| FT103 | マスアサインメント防御 | masslog | 14/14 | v1.5.37 | mass-assignment.md（DTO ホワイトリスト・権限フィールド隔離・レスポンス制御） |
| FT104 | Webhook シグネチャ検証 | hmaclog | 13/13 | v1.5.38 | webhook-signature.md（hash_equals() vs ===・タイミング攻撃・リプレイ防止） |
| FT105 | 楽観的ロック | optlocklog | 12/12 | v1.5.39 | optimistic-locking.md（失われた更新・WHERE version=?・409 応答設計） |
| FT106 | ETag・条件付きリクエスト | etaglog | 15/15 | v1.5.40 | etag-conditional-requests.md（304・412・428・ダブルクォート必須・ISO 8601） |
| FT107 | レートリミット | throttlelog | 9/9 | v1.5.41 | rate-limiting.md（ThrottleMiddleware・InMemoryRateLimitStorage 本番非推奨・リバースプロキシ・keyExtractor） |
| FT108 | ソフトデリート | softdeletelog | 18/18 | v1.5.42 | soft-delete.md（WHERE deleted_at IS NULL 必須・includeTrashed・purge ガード・insert()） |
| FT109 | パスワードハッシュ | pwdlog | 14/14 | v1.5.43 | password-hashing.md（Argon2id・DatabaseConstraintException・ユーザー列挙防止・dummy hash） |
| FT110 | JWT 認証 | jwtlog | 14/14 | v1.5.44 | jwt-authentication.md（LocalBearerTokenVerifier Issuer兼用・excludedPaths・nene2.auth.claims・exp int必須・alg:none拒否） |
| FT111 | RBAC | rbaclog | 14/14 | v1.5.45 | rbac.md（JWT クレームロール・requireRole パターン・401 vs 403・BearerTokenMiddleware メソッド非区別・createEmpty） |
| FT112 | マルチテナント隔離 | tenantlog | 13/13 | v1.5.46 | multi-tenant-isolation.md（全クエリに tenant_id フィルター必須・クロステナント 404・JWT クレームにテナント ID・レスポンスから tenant_id 除外） |
| FT113 | JWT Refresh Token Rotation | refreshlog | 15/15 | v1.5.47 | refresh-token-rotation.md（リフレッシュトークンをハッシュ保存・ローテーション・リプレイ攻撃検知・ログアウト常に 204・jti クレーム） |
| FT114 | 監査ログ（Audit Trail）| auditlog | 17/17 | v1.5.48 | audit-trail.md（監査はハンドラレイヤー・before/after スナップショット・immutable・JWT クレームからアクター取得・ORDER BY id DESC）脆弱性診断: ダミーハッシュ不正形式・所有権チェック漏れを修正 |
| FT115 | API バージョニング | versionlog | 14/14 | v1.5.49 | api-versioning.md（URI プレフィックス・Deprecation/Sunset ヘッダー RFC 8594・toV1/toV2 変換・ストレージ共有） |
| FT116 | バックグラウンドジョブキュー | queuelog | 27/27 | v1.5.50 | job-queue.md（優先度キュー・リトライロジックはリポジトリ層・retry_count/max_retries・idempotency_key UNIQUE 制約・max_retries=0 で即失敗） |
| FT117 | API キー管理 | apikeylog | 19/19 | v1.5.51 | api-key-management.md（SHA-256 ハッシュ保存・prefix+hash_equals 2段階認証・スコープ階層・rotate は create-first・**脆弱性診断: prefix が常に 'nk' で全テーブルスキャン→修正・rotate 非アトミック→create-first に修正**） |
| FT118 | 署名付き URL | signedlog | 17/17 | v1.5.52 | signed-urls.md（HMAC-SHA256 トークン・hash_equals 必須・410 Gone vs 401 設計・stateless 検証・secret rotation パターン） |
| FT119 | サーキットブレーカー | circuitlog | 15/15 | v1.5.53 | circuit-breaker.md（3状態遷移・lazy Half-Open・DB 永続化・連続失敗カウント・503 応答） |
| FT120 | アウトバウンド Webhook 配信 | webhookdeliverylog | 31/31 | v1.5.54 | webhook-delivery.md（SSRF URL 検証・timestamp 付き HMAC・secret ハッシュ保存・リトライ）**脆弱性診断: null safety 修正** ／ **クラッカー攻撃試験: 16 攻撃すべて耐久** |
| FT121 | フィーチャーフラグ | featureflaglog | 21/21 | v1.5.55 | feature-flags.md（評価優先順位・rollout_pct crc32 バケット・ターゲット upsert・kill switch パターン） |
| FT122 | 分散ロック | distlocklog | 16/16 | v1.5.56 | distributed-locking.md（owner 強制・stale lock claim・非ブロッキング取得・409 vs 403 設計） |
| FT123 | 個人データエクスポート | exportlog | 19/19 | v1.5.57 | personal-data-export.md（opaque token・センシティブフィールド除外・expiry 二重チェック）**脆弱性診断: 期限切れエクスポートの孤児 PII レコード生成を修正** |
| FT124 | ユーザー招待システム | invitelog | 26/26 | v1.5.58 | user-invitation.md（256-bit token・expiry before status check・cancel 403 vs 404・INSERT 引数順バグ修正）**クラッカー攻撃試験: 12 攻撃すべて耐久** |
| FT125 | タグシステム（M:N）| taglog | 20/20 | v1.5.59 | tagging-system.md（join table・原子的タグ差し替え・N+1 防止 IN クエリ・タグ別検索） |
| FT126 | パスワードリセット | resetlog | 15/15 | v1.5.60 | password-reset.md（SHA-256 ハッシュ保存・常に 202・旧トークン無効化・expiry before used チェック）**脆弱性診断: user_id レスポンス露出を修正** |
| FT127 | スレッドコメント | commentlog | 20/20 | v1.5.61 | threaded-comments.md（parent_id 自己参照・depth 非正規化・MAX_DEPTH=3・ソフトデリート子保持・2パスツリー組み立て・PHPStan-safe） |
| FT128 | アカウントロックアウト | lockoutlog | 32/32 | v1.5.62 | account-lockout.md（per-account 失敗カウント・locked_until・423・ユーザー列挙防止・MySQL スキーマ）**クラッカー攻撃試験: 12 攻撃すべて耐久** / **MySQL 統合テスト初導入: 5テスト** |
| FT129 | イベントソーシング（基本） | eventsourcelog | 17/17 | v1.5.63 | event-sourcing.md（append-only イベントログ・リプレイ・ORDER BY id ASC）**脆弱性診断: VULN-A 整数オーバーフロー（is_int + 上限 1e9）修正** |
| FT130 | 通知インボックス | notificationlog | 23/23 | v1.5.64 | notification-inbox.md（read_at nullable・冪等マーク・クロスユーザー 404・bulk read-all） |
| FT131 | コメント投票 | votelog | 20/20 | v1.5.65 | voting-system.md（upvote/downvote toggle・VoteDirection enum・UNIQUE 制約・スコア同梱） |
| FT132 | プロフィール管理 | profilelog | 32/32 | v1.5.66 | user-profile-management.md（avatar_url https 強制・DatabaseConstraintException 409・mb_strlen・X-User-Id 所有権）**クラッカー攻撃試験: 12 攻撃全耐久** / **脆弱性診断: VULN-A 重複メール 500 → 409** |
| FT133 | ブックマーク | bookmarklog | 22/22 | v1.5.67 | bookmark-system.md（冪等 add・DatabaseConstraintException catch・コレクションフィルタ・204 vs 404）**MySQL 統合テスト: 5テスト** |
| FT134 | ユーザーフォロー | followlog | 20/20 | v1.5.68 | user-follow-system.md（冪等フォロー 201/200・自己フォロー 422・相互フォロー・ORDER BY id DESC） |
| FT135 | ダイレクトメッセージ | messagelog | 31/31 | v1.5.69 | direct-messaging-system.md（双方向会話検索・参加者アクセス制御・GET で parse() 禁止）**脆弱性診断: 12 攻撃全耐久** |
| FT136 | アクセストークン管理 | tokenlog | 29/29 | v1.5.70 | access-token-management.md（SHA-256・TokenScope enum・二重所有権チェック・verify 常に 200）**クラッカー攻撃試験: 12 攻撃全耐久** |
| FT137 | サブスクリプション管理 | planlog | 20/20 | v1.5.71 | subscription-plan-management.md（UNIQUE制約→re-subscribe UPDATE・PUT要アクティブ・cancel 404 vs 409） |
| FT138 | グループメンバーシップ | grouplog | 38/38 | v1.5.72 | group-membership-management.md（`user_groups`・MemberRole enum権限メソッド・owner自動追加・自己脱退）**脆弱性診断: 12件全Pass** / **MySQL 統合テスト: 5テスト** |
| FT139 | ゲスト注文 | orderlog | 23/23 | v1.5.73 | guest-order-system.md（price snapshot・在庫先行検証・カート数量加算・ユーザー別カート分離） |
| FT140 | フラッシュセール | salelog | 29/29 | v1.5.74 | flash-sale-system.md（COUNT残数計算・ISO 8601時間比較・UNIQUE二重購入防止・matchステータス）**クラッカー攻撃試験: 12件全Pass** |
| FT141 | リーダーボード | ranklog | 29/29 | v1.5.75 | leaderboard-ranking-system.md（ベストスコア UPDATE・COUNT(*)ランク計算・IDOR防止・limitクランプ）**脆弱性診断: 12件全Pass** |
| FT142 | コンテンツ下書き | draftlog | 20/20 | v1.5.76 | content-draft-lifecycle.md（ArticleStatus enum遷移ガード・404隠蔽・同秒ソート id DESC tiebreaker） |
| FT143 | 絵文字リアクション | emojilog | 23/23 | v1.5.77 | emoji-reaction-system.md（UNIQUE(post_id,user_id,emoji)・GROUP BY集計・optional actor tracking・mb_strlen）**MySQL統合テスト: 5テスト** |
| FT144 | パスワードレス認証（Magic Link）| magiclog | 43/43 | v1.5.78 | passwordless-auth-magic-link.md（SHA-256ハッシュ保存・常に202・expiry before used_at・セッション無効化）**脆弱性診断: 12件全Pass** / **クラッカー攻撃試験: 12件全Pass** |
| FT145 | ユーザー設定管理 | preflog | 20/20 | v1.5.79 | user-preferences-management.md（PreferenceKey enum・型バリデーション・upsert・デフォルト値フォールバック・IDOR防止） |
| FT146 | コンテンツピン留め | pinlog | 19/19 | v1.5.80 | content-pinning.md（position連続管理・冪等追加201/200・unpin後位置詰め・完全一致reorder） |
| FT147 | コンテンツ通報・モデレーション | reportlog | 32/32 | v1.5.81 | content-report-moderation.md（RBAC・IDOR防止・冪等通報201/200・一方向ステータス遷移）**脆弱性診断: VULN-A〜L 12件全Pass** |
| FT148 | OTP 認証システム | otplog | 35/35 | v1.5.82 | otp-authentication.md（SHA-256ハッシュ・3回ロックアウト・最新OTPのみ有効・setRiskyAllowed）**クラッカー攻撃試験: 12件全Pass** / **MySQL統合テスト: 5件全Pass** |
| FT149 | コンテンツコレクション | collectionlog | 20/20 | v1.5.83 | content-collection.md（存在非公開404・冪等追加201/200・位置詰め整合・複数パスパラメータ） |
| FT150 | クーポン・プロモコード管理 | couponlog | 34/34 | v1.5.84 | coupon-promo-code.md（admin RBAC・ユーザー1回制限・discount_pct制約・user_id注入防止）**脆弱性診断: VULN-A〜L 12件全Pass** |
| FT151 | ウィッシュリスト管理 | wishlistlog | 23/23 | v1.5.85 | wishlist-management.md（存在非公開404・冪等追加201/200・priority/noteメタデータ・フォールバックバリデーション） |
| FT152 | ポイント・ロイヤルティシステム | pointlog | 30/30 | v1.5.86 | point-loyalty-system.md（トランザクション履歴残高・冪等化reference_id・残高多層防御）**クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT153 | アクティビティフィード | feedlog | 57/57 | v1.5.87 | activity-feed.md（フォローベースフィード・カーソルページネーション・プライバシー制御）**脆弱性診断: VULN-A〜L 全Pass** / **MySQL統合テスト: 5件全Pass** |
| FT154 | プロダクトレビュー・評価システム | reviewlog | 29/29 | v1.5.88 | product-review-system.md（1ユーザー1商品1レビュー・評価集計・所有権ガード） |
| FT155 | ショッピングカート | cartlog | 28/28 | v1.5.89 | shopping-cart.md（UNIQUE制約・数量加算冪等・quantity=0削除・price都度計算・RuntimeApplicationFactory必須） |
| FT156 | ファイルメタデータ管理・共有 | filelog | 59/59 | v1.5.90 | file-metadata-sharing.md（3段階アクセス制御・IDOR防止 404・visibility エスカレーション防止・FK順序削除）**脆弱性診断: VULN-A〜L 全Pass** / **クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT157 | 全文検索・オートコンプリート | searchlog | 22/22 | v1.5.91 | search-autocomplete.md（LIKE 特殊文字エスケープ・! エスケープ文字・関連度スコア 3段階・前方一致オートコンプリート・limit クランプ） |
| FT158 | CSV バルクインポート | importlog | 22/22 | v1.5.92 | csv-bulk-import.md（str_getcsv $escape 必須化・部分成功・バッチ内重複検知・CRLF 対応・errors JSON 永続化）**MySQL 統合テスト: 5テスト** |
| FT159 | TOTP 二要素認証 | totplog | 32/32 | v1.5.93 | totp-authentication.md（RFC 6238・Base32・リプレイ防止 used_totp_steps・window=1 許容・hash_equals タイミング攻撃防止）**脆弱性診断: VULN-A〜L 全Pass** |
| FT160 | OAuth2 Social Login | oauthlog | 22/22 | v1.5.94 | oauth2-social-login.md（Authorization Code Flow・state CSRF 防止・コードリプレイ防止・セッション無効化）**クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT161 | Application Caching | cachelog | 20/20 | v1.5.95 | application-caching.md（Cache-Aside・TTL クロック注入・書き込み時無効化・ヒット率統計） |
| FT162 | Content Versioning | contentvlog | 18/18 | v1.5.96 | content-versioning.md（append-only 履歴・バージョン一覧・ロールバック = 新バージョン）|
| FT163 | Payment Webhook 受信 | paymentlog | 18/18 | v1.5.97 | payment-webhook.md（HMAC-SHA256 署名検証・event_id 冪等処理・ステータス遷移ガード 409） |
| FT164 | Geolocation | geoloclog | 25/25 | v1.5.98 | geolocation.md（Haversine 距離・バウンディングボックス2パス・固定パス順序・座標バリデーション）**クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT165 | A/B Testing | ablog | 16/16 | v1.5.99 | ab-testing.md（draft→active→stopped遷移・crc32決定論的割当・冪等・CVR集計） |
| FT166 | Multi-step Workflow | stepflowlog | 18/18 | v1.5.100 | multi-step-workflow.md（順序付きステップ・approve→次ステップ/完了・reject→即終了・アクション履歴） |
| FT167 | Inbound Webhook Receiver | inboundlog | 17/17 | v1.5.101 | inbound-webhook-receiver.md（per-source HMAC secret・署名検証→冪等→保存順序・UNIQUE(source_id,event_id)）**MySQL 統合テスト: 5件全Pass** |
| FT168 | Admin Report Aggregation | agglog | 26/26 | v1.5.102 | admin-report-aggregation.md（日付バリデーション・from>to拒否・limit クランプ・COALESCE NULL 防止）**クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT169 | Data Masking | masklog | 24/24 | v1.5.103 | data-masking.md（デフォルトマスク・admin unmask・X-Accessor 強制・append-only 監査ログ）**脆弱性診断: VULN-A〜L 全Pass** |
| FT170 | Request Deduplication | deduplog | 24/24 | v1.5.104 | request-deduplication.md（Idempotency-Key 必須・24h TTL・replayed フラグ・ctype_digit 型検証）**クラッカー攻撃試験: ATK-01〜12 全Pass** |
| FT171 | Hierarchical Data | hierarchylog | 21/21 | v1.5.105 | hierarchical-data.md（自己参照FK + マテリアライズドパス・MAX_DEPTH=5・循環参照検出・サブツリーカスケード） |
| FT172 | Content Scheduling | pubschedulelog | 34/34 | v1.5.106 予定 | content-scheduling.md（publish_at 時間指定・draft/scheduled/published/archived 状態機械・publish-due 一括トリガー・hash_equals admin key）**脆弱性診断: VULN-A〜L 全Pass** ／ **クラッカー攻撃試験: ATK-01〜12 全Pass** |

## 次のアクション（2026-05-26〜）

### FT ループ継続（Phase IV）

| FT | テーマ案 | 備考 |
|---|---|---|
| ~~FT170~~ | ~~Request Deduplication（deduplog）~~ | ~~完了 v1.5.104~~ |
| ~~FT171~~ | ~~Hierarchical Data（hierarchylog）~~ | ~~完了 v1.5.105~~ |
| 🔄 FT172 | Content Scheduling（pubschedulelog） | 実装完了・PR 作成前 |
| 📋 FT173 | 次テーマ | — |

### ループ終了後（FT170 以降）— 完了・進行状況

| タスク | 状況 | PR |
|---|---|---|
| **発見可能性** llms.txt に全 100 howto を追記 | ✅ 完了 | #876 |
| **src/ 還元 batch 1** UtcClock + SecureTokenHelper | ✅ 完了 | #878 |
| **FT 公開** NENE2-examples monorepo 作成（73 実装） | ✅ 完了 | #880 |
| VitePress サイトへの新規 howto ページ追加 | 🔄 継続（検索で代替可） | — |
| **v2.0 設計検討**: FT ループで判明した摩擦点の還元 | 🔄 継続（src/ 還元 batch 2〜） | — |
| src/ 還元 batch 2: JSON ボディ厳密型バリデーター等 | 📋 次候補 | — |

### 次のトリガー値

| チェック項目 | 次回 |
|---|---|
| MySQL 統合テスト | FT167 ✓ 完了 |
| 脆弱性診断 | FT172 ✓ 完了（次: FT175） |
| クラッカー攻撃試験 | FT172 ✓ 完了（次: FT176） |

## 検討事項（決定不要・議題として保持）

### src/ 還元 batch 2 の候補

FT ループで証明されたがまだ `src/` にないパターン:

- JSON ボディ厳密整数バリデーター（`ctype_digit` ベース、`QueryStringParser::int()` と対称）
- IdempotencyKey ミドルウェア（24h TTL・replayed フラグ・ストレージ抽象）

## Open Issues

なし

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- FT ループが毎回 main にマージするため、リリースのたびにこのファイルも更新すること。
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
