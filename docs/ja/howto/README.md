# ハウツーガイド インデックス

NENE2 で構築するためのタスク指向ガイド集。各ガイドは自己完結しており、関連トピックへのリンクを含みます。

**100 件以上のガイド**がこのディレクトリにあります（このインデックスを除く）。VitePress サイドバーには主なエントリポイントが一覧されます。全カタログはこのページで確認してください。

---

## 構築したいものから探す

> 技術名でガイドが見つからない場合はこちらから。

| やりたいこと | ガイド |
|-----------|-------|
| オプションのクエリパラメーターでリストをフィルタリングする（`?status=`、`?price_max=`） | [dynamic-filter-query.md](dynamic-filter-query.md) |
| 複数のタグ/スキルでフィルタリングする（AND: すべてを持つ必要あり） | [multi-value-tag-filter.md](multi-value-tag-filter.md) |
| クエリパラメーターで安全にソートする（`?sort=name&order=asc`） | [dynamic-sort-order-injection.md](dynamic-sort-order-injection.md) |
| リストエンドポイントにページネーションを追加する | [add-pagination.md](add-pagination.md) |
| 丸め誤差なしで金額を保存・計算する | [money-integer-arithmetic.md](money-integer-arithmetic.md) |
| ステータス遷移を管理する（draft → published → archived） | [state-machine-workflow-api.md](state-machine-workflow-api.md) |
| 階層を構築する（カテゴリ、フォルダ、組織図、地域） | [hierarchical-data.md](hierarchical-data.md) |
| イベント/履歴を保存する（追記のみ、更新しない） | [event-sourcing-cqrs-api.md](event-sourcing-cqrs-api.md) |
| 二重予約を防止する（ホテル、会議室、予約） | [prevent-double-booking.md](prevent-double-booking.md) |
| 限られた在庫や席での競合状態を防止する | [flash-sale-api.md](flash-sale-api.md) |
| 誰が何をいつ変更したかを記録する（監査証跡） | [audit-trail.md](audit-trail.md) |
| 投票/いいねを実装する（ユーザーごとに 1 回） | [upvote-downvote-api.md](upvote-downvote-api.md) |
| スレッドコメントやネストした返信を追加する | [threaded-comments-api.md](threaded-comments-api.md) |
| セキュアなトークンを生成する（招待リンク、ダウンロード URL、API キー） | [api-key-management.md](api-key-management.md) |
| タイムゾーンと UTC 保存を処理する | [handle-timezones.md](handle-timezones.md) |
| JWT 認証を追加する | [jwt-authentication.md](jwt-authentication.md) |
| マルチテナント分離を追加する（テナントごとのデータ分離） | [jwt-tenant-isolation.md](jwt-tenant-isolation.md) |
| パスワードをハッシュ化してログイン時に検証する | [password-auth-argon2id.md](password-auth-argon2id.md) |
| リーダーボード/ランキングを追加する | [game-score-leaderboard-api.md](game-score-leaderboard-api.md) |
| ファイルを安全にアップロード・提供する | [file-upload.md](file-upload.md) |
| 全文検索 | [sqlite-fts5-search.md](sqlite-fts5-search.md) |
| データベーストランザクションを使用する（複数の書き込みをアトミックにラップ） | [use-transactions.md](use-transactions.md) |
| ソフト削除（完全に削除せずに非表示） | [soft-delete.md](soft-delete.md) |
| 何かが起きたときに Webhook を送信する | [webhook-delivery-api.md](webhook-delivery-api.md) |
| 承認/レビューワークフローを実装する | [approval-workflow.md](approval-workflow.md) |
| ポイント、クレジット、残高台帳を管理する | [point-ledger-api.md](point-ledger-api.md) |
| クーポンと割引を管理する | [coupon-discount-api.md](coupon-discount-api.md) |
| レートリミットを実装する | [add-rate-limiting.md](add-rate-limiting.md) |
| サブスクリプション/メンバーシップを構築する | [subscription-plan-management.md](subscription-plan-management.md) |

---

## はじめる

| ガイド | 説明 |
|---|---|
| [カスタムルートを追加する](add-custom-route.md) | 新しい GET/POST/PUT/DELETE ルートを登録する |
| [データベース対応エンドポイントを追加する](add-database-endpoint.md) | Repository + executor + migration |
| [2 番目のエンティティを追加する](add-second-entity.md) | FK 関係、JOIN クエリ |
| [ヘルスチェックを追加する](add-health-check.md) | 生存確認エンドポイント |
| [HTML ビューを追加する](add-html-view.md) | ネイティブ PHP テンプレートによるサーバーサイドレンダリング |
| [品質ツール](quality-tools.md) | PHPStan、CS Fixer、PHPUnit のセットアップ |

---

## 認証・認可

| ガイド | 説明 |
|---|---|
| [JWT 認証](jwt-authentication.md) | LocalBearerTokenVerifier を使った Bearer トークン |
| [Bearer 認証を使う](use-bearer-auth.md) | BearerTokenMiddleware を適用する |
| [RBAC](rbac.md) | JWT クレームによるロールベースアクセス制御 |
| [マルチテナント分離](multi-tenant-isolation.md) | テナントごとのクエリフィルタリング |
| [JWT リフレッシュトークンローテーション](refresh-token-rotation.md) | セキュアなトークン更新、リプレイ検出 |
| [API キー管理](api-key-management.md) | SHA-256 キー保存、スコープ付きアクセス |
| [OTP 認証](otp-authentication.md) | 時間ベースまたは使い捨て OTP |
| [パスワードレス認証（マジックリンク）](passwordless-auth-magic-link.md) | メールトークンログイン |
| [パスワードハッシュ](password-hashing.md) | Argon2id、タイミングセーフな比較 |
| [パスワードリセット](password-reset.md) | セキュアなトークンフロー、一定時間レスポンス |
| [アクセストークン管理](access-token-management.md) | スコープ付きトークン、ローテーション、失効 |

---

## セキュリティ

| ガイド | 説明 |
|---|---|
| [SQL インジェクション防止](sql-injection.md) | パラメーター化クエリ、RouterParam キャスティング |
| [マスアサインメント防御](mass-assignment.md) | DTO による許可リスト |
| [CSRF と JSON API](csrf-and-json-api.md) | CORS ≠ CSRF; Content-Type ロック |
| [冪等性](idempotency.md) | 冪等性キー、201/200 設計 |
| [Webhook 署名検証](webhook-signature.md) | HMAC-SHA256、hash_equals |
| [リソースオーナーシップの強制](enforce-resource-ownership.md) | IDOR 防止のための 404 vs 403 |
| [署名付き URL](signed-urls.md) | ステートレス HMAC トークン、有効期限 |
| [アカウントロックアウト](account-lockout.md) | ログイン失敗カウント、locked_until |

---

## データベース

| ガイド | 説明 |
|---|---|
| [データベーストランザクション](transactions.md) | transactional() パターン概要 |
| [データベーストランザクションを使う](use-transactions.md) | ロールバックテスト付きのステップバイステップ |
| [楽観的ロック](optimistic-locking.md) | version カラム、競合時 409 |
| [ソフト削除](soft-delete.md) | deleted_at フィルター、パージガード |
| [二重予約防止](prevent-double-booking.md) | 時間範囲重複クエリ |
| [FTS5 検索を使う](use-fts5-search.md) | SQLite 全文検索 |
| [PostgreSQL を使う](use-postgresql.md) | アダプターを pgsql に切り替える |
| [マイグレーション](add-database-endpoint.md) | Phinx マイグレーション（add-database-endpoint 参照） |

---

## API 設計

| ガイド | 説明 |
|---|---|
| [ページネーション](pagination.md) | OFFSET とカーソルのトレードオフ |
| [ページネーションを追加する](add-pagination.md) | カーソルまたは OFFSET ページネーションの実装 |
| [API バージョニング](api-versioning.md) | URI プレフィックス、Deprecation/Sunset ヘッダー |
| [コンテントネゴシエーション](content-negotiation.md) | Accept ヘッダー処理 |
| [ETag と条件付きリクエスト](etag-conditional-requests.md) | 304、412、428 |
| [レートリミット](rate-limiting.md) | ThrottleMiddleware、ユーザーごとの制限 |
| [ネストした JSON バリデーション](nested-json-validation.md) | ドット記法エラー、深いバリデーション |
| [一括エンドポイントの実装](implement-bulk-endpoint.md) | バッチ作成/更新パターン |
| [PATCH エンドポイントの実装](implement-patch-endpoint.md) | 部分更新 |
| [タイムゾーンの処理](handle-timezones.md) | UTC 保存、ISO 8601 |
| [Unicode 入力のバリデーション](validate-unicode-input.md) | mb_strlen、書記素クラスター |
| [リクエストスコープ状態](request-scoped-state.md) | ミドルウェアパイプライン経由でデータを渡す |

---

## バックグラウンド & インフラ

| ガイド | 説明 |
|---|---|
| [ジョブキュー](job-queue.md) | 優先キュー、リトライ、冪等性キー |
| [サーキットブレーカー](circuit-breaker.md) | 3 状態、遅延ハーフオープン、DB 永続化 |
| [Webhook 配信](webhook-delivery.md) | アウトバウンド SSRF セーフ、署名付き、リトライ |
| [イベントソーシング](event-sourcing.md) | 追記のみログ、リプレイ |
| [フィーチャーフラグ](feature-flags.md) | ロールアウト %、ターゲティング、キルスイッチ |
| [分散ロック](distributed-locking.md) | オーナー強制、古いクレーム |
| [監査証跡](audit-trail.md) | 変更前後スナップショット、不変ログ |
| [ファイルアップロード](file-upload.md) | Base64、MIME 検出、パストラバーサル |
| [MCP ツールを追加する](add-mcp-tools.md) | API を MCP ツールとして公開する |
| [本番環境へのデプロイ](deploy-production.md) | 環境、シークレット、ヘルスチェック |

---

## プロダクト機能（レシピパターン）

| ガイド | 説明 |
|---|---|
| [アクティビティフィード](activity-feed.md) | フォローベースのフィード、カーソルページネーション、プライバシー |
| [ユーザーフォローシステム](user-follow-system.md) | 冪等なフォロー/アンフォロー、相互フォロー |
| [ダイレクトメッセージ](direct-messaging-system.md) | 会話モデル、参加者アクセス |
| [通知インボックス](notification-inbox.md) | 冪等な既読マーク、一括、IDOR |
| [コメントスレッド](threaded-comments.md) | 親子関係、MAX_DEPTH、ソフト削除 |
| [投票システム](voting-system.md) | 賛成/反対トグル、スコア |
| [絵文字リアクション](emoji-reaction-system.md) | UNIQUE 制約、GROUP BY カウント |
| [ブックマークシステム](bookmark-system.md) | 冪等な追加、コレクションフィルター |
| [ウィッシュリスト管理](wishlist-management.md) | プライバシー、優先度メタデータ、IDOR |
| [タグ付けシステム](tagging-system.md) | M:N 結合、アトミック置換、タグ検索 |
| [ユーザープロフィール](user-profile-management.md) | アバター URL、重複メール 409 |
| [ユーザー設定](user-preferences-management.md) | 型付き upsert、enum キー |
| [コンテンツ下書き](content-draft-lifecycle.md) | ステータス enum 遷移、非表示の 404 |
| [コンテンツピン](content-pinning.md) | 位置管理、並び替え |
| [コンテンツコレクション](content-collection.md) | 冪等な追加、位置補完 |
| [コンテンツモデレーション](content-report-moderation.md) | RBAC、冪等な報告、ステートマシン |
| [リーダーボード](leaderboard-ranking-system.md) | ベストスコア、COUNT ランク、カーソル |
| [ポイント/ロイヤルティシステム](point-loyalty-system.md) | 台帳モデル、reference_id 冪等性 |
| [フラッシュセール](flash-sale-system.md) | 在庫競合、UNIQUE 防止 |
| [ゲスト注文](guest-order-system.md) | 価格スナップショット、カート、在庫チェック |
| [サブスクリプションプラン](subscription-plan-management.md) | プランライフサイクル、再サブスクリプション |
| [ユーザー招待](user-invitation.md) | トークン、有効期限、キャンセルオーナーシップ |
| [グループメンバーシップ](group-membership-management.md) | ロール、オーナー自動参加、自己退出 |
| [クーポン/プロモコード](coupon-promo-code.md) | 管理者 RBAC、ユーザーごとの制限 |
| [商品レビュー](product-review-system.md) | 1 ユーザー 1 商品、レーティング集計 |
| [ショッピングカート](shopping-cart.md) | UNIQUE 制約、数量累積、quantity=0 削除 |
| [ファイルメタデータ共有](file-metadata-sharing.md) | 3 層アクセス制御、IDOR 防止、可視性ガード |
| [検索 & オートコンプリート](search-autocomplete.md) | LIKE エスケープ、関連性スコアリング、プレフィックスオートコンプリート |
| [CSV 一括インポート](csv-bulk-import.md) | 部分成功、バッチ重複検出、CRLF |
| [TOTP 二要素認証](totp-authentication.md) | RFC 6238、Base32、リプレイ防止 |
| [OAuth2 ソーシャルログイン](oauth2-social-login.md) | Authorization Code Flow、state CSRF、コードリプレイガード |
| [アプリケーションキャッシング](application-caching.md) | Cache-Aside、TTL インジェクション、書き込み無効化 |
| [コンテンツバージョニング](content-versioning.md) | 追記のみ履歴、新バージョンとしてのロールバック |
| [決済 Webhook](payment-webhook.md) | HMAC 署名、event_id 冪等性、ステータスガード |
| [ジオロケーション](geolocation.md) | ハーバーサイン距離、バウンディングボックス、座標バリデーション |
| [A/B テスト](ab-testing.md) | 実験ライフサイクル、決定論的割り当て、CVR |
| [マルチステップワークフロー](multi-step-workflow.md) | 順序付きステップ、承認/拒否、アクション履歴 |
| [インバウンド Webhook レシーバー](inbound-webhook-receiver.md) | ソースごとの HMAC、署名→冪等性→永続化 |
| [管理レポート集計](admin-report-aggregation.md) | 日付バリデーション、from>to ガード、制限クランプ |
| [データマスキング](data-masking.md) | デフォルトマスク、管理者アンマスク、追記のみ監査 |
| [リクエスト重複排除](request-deduplication.md) | Idempotency-Key、24h TTL、replayed フラグ |
| [個人データエクスポート](personal-data-export.md) | 不透明トークン、PII 有効期限 |

---

<!-- AUTO-INDEX:START (generated by `composer howto:index` — do not edit by hand) -->

## 全索引（自動生成）

このディレクトリの全ガイド（ファイル名順）。`composer howto:index` で再生成する。

| Guide | Title |
|---|---|
| [ab-testing.md](ab-testing.md) | ハウツー: A/B テストフレームワーク |
| [access-token-management.md](access-token-management.md) | NENE2 でアクセストークン管理を構築する方法 |
| [account-lockout.md](account-lockout.md) | アカウントロックアウト（ブルートフォース保護） |
| [activity-feed.md](activity-feed.md) | ハウツー: アクティビティフィード / タイムライン API |
| [add-custom-route.md](add-custom-route.md) | カスタムルートを追加する |
| [add-database-endpoint.md](add-database-endpoint.md) | DB 付きエンドポイントを追加する |
| [add-domain-exception-handler.md](add-domain-exception-handler.md) | ドメイン例外ハンドラーを追加する方法 |
| [add-health-check.md](add-health-check.md) | ヘルスチェックを追加する |
| [add-html-view.md](add-html-view.md) | HTML ビューを追加する |
| [add-jwt-authentication.md](add-jwt-authentication.md) | JWT 認証の追加 |
| [add-mcp-tools.md](add-mcp-tools.md) | MCP ツールを追加する |
| [add-optimistic-locking.md](add-optimistic-locking.md) | 楽観的同時実行制御（ETag / If-Match）を追加する方法 |
| [add-pagination.md](add-pagination.md) | ページネーションを追加する |
| [add-rate-limiting.md](add-rate-limiting.md) | レート制限を追加する |
| [add-second-entity.md](add-second-entity.md) | 2 つ目のドメインエンティティを追加する |
| [admin-report-aggregation.md](admin-report-aggregation.md) | 管理レポート集計を追加する方法 |
| [aggregate-reporting.md](aggregate-reporting.md) | ハウツー: 集計レポート API |
| [api-key-management.md](api-key-management.md) | API キー管理 |
| [api-usage-metering.md](api-usage-metering.md) | ハウツー: API 使用量計測 & クォータ管理 |
| [api-versioning.md](api-versioning.md) | ハウツー: API バージョニング |
| [application-caching.md](application-caching.md) | Application Caching の実装ガイド |
| [approval-workflow.md](approval-workflow.md) | ハウツー: 承認ワークフロー API |
| [article-relations-api.md](article-relations-api.md) | ハウツー: 記事リレーション API |
| [article-versioning-api.md](article-versioning-api.md) | ハウツー: 記事バージョニング API |
| [asset-checkout.md](asset-checkout.md) | ハウツー: 資産チェックアウト / チェックイン管理 |
| [audit-trail.md](audit-trail.md) | HOWTO: 監査証跡 — 誰が何を変更したかを記録する |
| [batch-api-partial-success.md](batch-api-partial-success.md) | ハウツー: 部分成功を伴うバッチ API |
| [bearer-token-middleware.md](bearer-token-middleware.md) | ハウツー: Bearer トークンミドルウェア（JWT 認証のエッジケース） |
| [bookmark-api.md](bookmark-api.md) | ハウツー: ブックマーク API |
| [bookmark-system.md](bookmark-system.md) | ブックマークシステム |
| [budget-tracking.md](budget-tracking.md) | ハウツー: 予算追跡 API |
| [bulk-operations-partial-success.md](bulk-operations-partial-success.md) | ハウツー: 部分成功セマンティクスを持つバルク操作 |
| [bulk-reorder-api.md](bulk-reorder-api.md) | ハウツー: 一括並べ替え（ドラッグ&ドロップ順序）API |
| [bulk-status-update.md](bulk-status-update.md) | ハウツー: バルクステータス更新 API |
| [category-hierarchy-api.md](category-hierarchy-api.md) | How-to: カテゴリ階層ツリー API |
| [circuit-breaker.md](circuit-breaker.md) | How-to: Circuit Breaker |
| [collection-api.md](collection-api.md) | How-to: コレクション API（ユーザーキュレートリスト） |
| [comment-thread.md](comment-thread.md) | How-to: コメントスレッド API |
| [contact-management.md](contact-management.md) | How-to: 連絡先管理 API |
| [content-approval-workflow.md](content-approval-workflow.md) | How-to: コンテンツ承認ワークフロー |
| [content-collection.md](content-collection.md) | コンテンツコレクション |
| [content-draft-lifecycle.md](content-draft-lifecycle.md) | NENE2 でコンテンツ Draft ライフサイクル（Draft → Published → Archived）を構築する |
| [content-negotiation-api.md](content-negotiation-api.md) | How-to: コンテンツネゴシエーション — JSON API |
| [content-negotiation.md](content-negotiation.md) | コンテンツネゴシエーション |
| [content-pinning.md](content-pinning.md) | Content Pinning |
| [content-relations.md](content-relations.md) | コンテンツリレーション — 型付き M:N 自己参照リンク |
| [content-report-moderation.md](content-report-moderation.md) | Content Report & Moderation |
| [content-reporting.md](content-reporting.md) | ハウツー: コンテンツ通報システム |
| [content-scheduling.md](content-scheduling.md) | コンテンツスケジューリング — ライフサイクル状態を持つ時刻ベース公開 |
| [content-versioning.md](content-versioning.md) | Content Versioning の実装ガイド |
| [coupon-discount-api.md](coupon-discount-api.md) | ハウツー: クーポン割引コード API |
| [coupon-promo-code.md](coupon-promo-code.md) | クーポン・プロモコード管理 |
| [coupon-redemption.md](coupon-redemption.md) | ハウツー: クーポン / 割引コード利用 API |
| [cqrs-pattern.md](cqrs-pattern.md) | ハウツー: CQRS パターン |
| [credit-ledger.md](credit-ledger.md) | ハウツー: クレジット台帳 API |
| [csrf-and-json-api.md](csrf-and-json-api.md) | CSRF と JSON API |
| [csv-bulk-import.md](csv-bulk-import.md) | CSV バルクインポート API の実装ガイド |
| [csv-export-formula-injection.md](csv-export-formula-injection.md) | ハウツー: エクスポート時の CSV / スプレッドシート数式インジェクションを防ぐ |
| [cursor-pagination.md](cursor-pagination.md) | ハウツー: カーソルベースページネーション |
| [data-export-api.md](data-export-api.md) | ハウツー: データエクスポート API |
| [data-masking.md](data-masking.md) | データマスキングの追加方法 |
| [dead-letter-queue.md](dead-letter-queue.md) | ハウツー: デッドレターキュー（DLQ） |
| [delegated-access-grants.md](delegated-access-grants.md) | ハウツー: 委任アクセスグラント |
| [deploy-production.md](deploy-production.md) | 本番環境へデプロイする |
| [direct-messaging-system.md](direct-messaging-system.md) | NENE2 でダイレクトメッセージシステムを構築する方法 |
| [distributed-lock.md](distributed-lock.md) | ハウツー: 分散ロック |
| [distributed-locking.md](distributed-locking.md) | 分散ロッキング |
| [document-template-engine.md](document-template-engine.md) | ハウツー: ドキュメントテンプレートエンジン |
| [document-versioning.md](document-versioning.md) | ハウツー: ドキュメントバージョニング API |
| [draft-publish-workflow.md](draft-publish-workflow.md) | ハウツー: ドラフト → 公開 → アーカイブ ワークフロー |
| [dynamic-filter-query.md](dynamic-filter-query.md) | ハウツー: 動的フィルタークエリ（動的 WHERE 句） |
| [dynamic-sort-order-injection.md](dynamic-sort-order-injection.md) | ハウツー: 動的ソート・フィルターと ORDER BY インジェクション防止 |
| [emoji-reaction-system.md](emoji-reaction-system.md) | NENE2 で絵文字リアクションシステムを構築する方法 |
| [emoji-reactions-api.md](emoji-reactions-api.md) | ハウツー: 絵文字リアクション API |
| [emoji-reactions-toggle.md](emoji-reactions-toggle.md) | ハウツー: トグルとグループ化カウントを持つ絵文字リアクション |
| [encrypted-field-storage.md](encrypted-field-storage.md) | 暗号化フィールドストレージの構築方法 |
| [enforce-resource-ownership.md](enforce-resource-ownership.md) | リソースオーナーシップの強制（IDOR 防止） |
| [etag-conditional-requests.md](etag-conditional-requests.md) | ETag と条件付きリクエスト |
| [event-analytics-api.md](event-analytics-api.md) | ハウツー: イベントアナリティクス API |
| [event-analytics.md](event-analytics.md) | ハウツー: イベントアナリティクス API |
| [event-sourcing-cqrs-api.md](event-sourcing-cqrs-api.md) | ハウツー: イベントソーシング & CQRS API |
| [event-sourcing-ledger.md](event-sourcing-ledger.md) | ハウツー: イベントソーシング台帳 |
| [event-sourcing.md](event-sourcing.md) | イベントソーシング（基本） |
| [event-ticket-booking.md](event-ticket-booking.md) | ハウツー: イベントチケット予約 |
| [expense-tracker.md](expense-tracker.md) | ハウツー: 経費トラッカー API |
| [expense-tracking-api.md](expense-tracking-api.md) | ハウツー: 経費追跡 API |
| [feature-flag-api.md](feature-flag-api.md) | ハウツー: フィーチャーフラグ API |
| [feature-flags.md](feature-flags.md) | ハウツー: フィーチャーフラグ API |
| [feedback-collection.md](feedback-collection.md) | ハウツー: フィードバック収集 API |
| [file-metadata-sharing.md](file-metadata-sharing.md) | ファイルメタデータ管理・共有 API の実装ガイド |
| [file-sharing-api.md](file-sharing-api.md) | ハウツー: ファイル共有 API |
| [file-upload-metadata.md](file-upload-metadata.md) | ハウツー: ファイルアップロードメタデータ API（VULN-A〜L） |
| [file-upload.md](file-upload.md) | ファイルアップロード（base64 JSON） |
| [fixed-window-rate-limiter.md](fixed-window-rate-limiter.md) | ハウツー: 固定ウィンドウ レートリミッター |
| [flash-sale-api.md](flash-sale-api.md) | ハウツー: フラッシュセール API |
| [flash-sale-system.md](flash-sale-system.md) | NENE2 でフラッシュセールシステムを構築する方法 |
| [follow-api.md](follow-api.md) | ハウツー: フォロー / アンフォロー API |
| [ft-registry.md](ft-registry.md) | FT Registry |
| [game-score-leaderboard-api.md](game-score-leaderboard-api.md) | ハウツー: ゲームスコア & リーダーボード API |
| [geolocation-api.md](geolocation-api.md) | ハウツー: ジオロケーション API |
| [geolocation.md](geolocation.md) | ジオロケーション検索の追加方法 |
| [group-member-management.md](group-member-management.md) | ハウツー: グループメンバー管理 |
| [group-membership-management.md](group-membership-management.md) | NENE2 でグループメンバーシップ管理を構築する方法 |
| [guest-order-system.md](guest-order-system.md) | NENE2 でゲスト注文システム（カート → 注文 → 注文明細）を構築する方法 |
| [habit-tracker.md](habit-tracker.md) | ハウツー: 習慣トラッカー API |
| [handle-timezones.md](handle-timezones.md) | タイムゾーンの処理方法 |
| [hierarchical-data.md](hierarchical-data.md) | 階層データ — 自己参照 FK + マテリアライズドパス |
| [idempotency-key-api.md](idempotency-key-api.md) | ハウツー: 冪等性キー API |
| [idempotency-key.md](idempotency-key.md) | ハウツー: 冪等性キー（リクエスト重複排除） |
| [idempotency.md](idempotency.md) | ハウツー: 冪等性キーパターン |
| [implement-bulk-endpoint.md](implement-bulk-endpoint.md) | ハウツー: 一括作成エンドポイントの実装 |
| [implement-patch-endpoint.md](implement-patch-endpoint.md) | ハウツー: PATCH エンドポイントの実装 |
| [inbound-webhook-gateway.md](inbound-webhook-gateway.md) | ハウツー: インバウンド Webhook ゲートウェイ |
| [inbound-webhook-receiver.md](inbound-webhook-receiver.md) | インバウンド Webhook レシーバーの追加方法 |
| [inventory-management.md](inventory-management.md) | ハウツー: 在庫管理 API |
| [inventory-stock-management.md](inventory-stock-management.md) | ハウツー: 在庫ストック管理 |
| [invitation-referral.md](invitation-referral.md) | ハウツー: 招待/紹介 API |
| [invitation-system.md](invitation-system.md) | ハウツー: 招待システム |
| [iso-datetime-validation.md](iso-datetime-validation.md) | ハウツー: タイムゾーン付き ISO 8601 日時のバリデーション |
| [job-queue-with-retry.md](job-queue-with-retry.md) | ハウツー: リトライと冪等性を備えたバックグラウンドジョブキュー |
| [job-queue.md](job-queue.md) | バックグラウンドジョブキュー（リトライと冪等性） |
| [json-merge-patch.md](json-merge-patch.md) | ハウツー: JSON マージパッチと ETag 競合検出 |
| [jwt-authentication.md](jwt-authentication.md) | ハウツー: JWT 認証 |
| [jwt-tenant-isolation.md](jwt-tenant-isolation.md) | ハウツー: JWT マルチテナント分離 |
| [leaderboard-ranking-api.md](leaderboard-ranking-api.md) | ハウツー: リーダーボードランキング API |
| [leaderboard-ranking-system.md](leaderboard-ranking-system.md) | NENE2 でリーダーボード（ランキングシステム）を構築する方法 |
| [leaderboard-ranking.md](leaderboard-ranking.md) | ハウツー: ゲームリーダーボード & ランキング API |
| [leaderboard-scores.md](leaderboard-scores.md) | ハウツー: リーダーボード & スコアトラッキング API |
| [live-penetration-testing.md](live-penetration-testing.md) | ハウツー: ライブコンテナペネトレーションテスト |
| [live-poll-system.md](live-poll-system.md) | ハウツー: ライブポールシステム |
| [magic-link-authentication.md](magic-link-authentication.md) | ハウツー: マジックリンク認証 |
| [mass-assignment-defence.md](mass-assignment-defence.md) | ハウツー: 明示的 DTO によるマス代入防御 |
| [mass-assignment.md](mass-assignment.md) | マス代入防御 |
| [media-watchlist.md](media-watchlist.md) | ハウツー: メディアウォッチリスト API |
| [money-integer-arithmetic.md](money-integer-arithmetic.md) | ハウツー: 金額と整数演算 |
| [multi-currency-money-ledger.md](multi-currency-money-ledger.md) | ハウツー: 整数セントを使ったマルチ通貨金銭台帳 |
| [multi-currency-wallet.md](multi-currency-wallet.md) | ハウツー: マルチ通貨ウォレット |
| [multi-step-workflow.md](multi-step-workflow.md) | マルチステップワークフローの追加方法 |
| [multi-tenant-isolation.md](multi-tenant-isolation.md) | ハウツー: マルチテナント分離 |
| [multi-value-tag-filter.md](multi-value-tag-filter.md) | ハウツー: マルチ値タグフィルター API |
| [multilingual-content.md](multilingual-content.md) | ハウツー: 多言語コンテンツ API |
| [nested-json-validation.md](nested-json-validation.md) | ハウツー: ネスト JSON バリデーション |
| [note-management-ownership.md](note-management-ownership.md) | ハウツー: オーナーシップ付きノート管理 |
| [note-management-with-tags.md](note-management-with-tags.md) | ハウツー: タグ付きノート管理 |
| [notification-inbox.md](notification-inbox.md) | ハウツー: 通知受信トレイ API |
| [notification-queue.md](notification-queue.md) | ハウツー: 通知キュー API |
| [numeric-verification-code.md](numeric-verification-code.md) | 数値確認コードの構築方法 |
| [oauth2-social-login.md](oauth2-social-login.md) | OAuth2 Social Login の実装ガイド |
| [offset-cursor-pagination.md](offset-cursor-pagination.md) | ハウツー: オフセット & カーソルページネーション |
| [one-time-secrets.md](one-time-secrets.md) | ハウツー: ワンタイムシークレット API & ATK-01〜12 クラッカー攻撃テスト |
| [optimistic-concurrency-version.md](optimistic-concurrency-version.md) | ハウツー: 楽観的並行制御（バージョンフィールド） |
| [optimistic-lock-patch-version.md](optimistic-lock-patch-version.md) | ハウツー: PATCH + バージョンフィールドによる楽観的ロック |
| [optimistic-locking-etag.md](optimistic-locking-etag.md) | ハウツー: ETag / If-Match による楽観的ロック |
| [optimistic-locking.md](optimistic-locking.md) | 楽観的ロック |
| [order-management.md](order-management.md) | ハウツー: 注文管理 API |
| [otp-authentication.md](otp-authentication.md) | ハウツー: OTP 認証システム |
| [pagination-boundary-attack.md](pagination-boundary-attack.md) | ハウツー: ページネーション境界 & リミットインジェクション |
| [pagination-limit-injection.md](pagination-limit-injection.md) | ハウツー: ページネーション境界 & リミットインジェクション防止 |
| [pagination.md](pagination.md) | ページネーション |
| [password-auth-argon2id.md](password-auth-argon2id.md) | ハウツー: Argon2id によるパスワード認証 |
| [password-hashing.md](password-hashing.md) | ハウツー: パスワードハッシュ |
| [password-reset-flow.md](password-reset-flow.md) | ハウツー: パスワードリセットフロー |
| [password-reset.md](password-reset.md) | パスワードリセットフロー |
| [passwordless-auth-magic-link.md](passwordless-auth-magic-link.md) | Passwordless Auth (Magic Link) |
| [patch-partial-update.md](patch-partial-update.md) | ハウツー: PATCH 部分更新（JSON Merge Patch） |
| [payment-webhook.md](payment-webhook.md) | Payment Webhook 受信の実装ガイド |
| [personal-data-export.md](personal-data-export.md) | 個人データエクスポート |
| [pii-masking.md](pii-masking.md) | ハウツー: PII マスキング API |
| [pin-bookmark-ordering.md](pin-bookmark-ordering.md) | ハウツー: ピン/ブックマーク（並び順付き） |
| [pin-verification-lockout.md](pin-verification-lockout.md) | PIN 認証・ロックアウト |
| [point-ledger-api.md](point-ledger-api.md) | ハウツー: ポイント台帳 API |
| [point-loyalty-system.md](point-loyalty-system.md) | ポイント・ロイヤルティシステム |
| [poll-survey.md](poll-survey.md) | ハウツー: 投票 / アンケート API |
| [prevent-double-booking.md](prevent-double-booking.md) | 二重予約を防ぐ方法（予約と定員管理） |
| [price-history.md](price-history.md) | ハウツー: 商品価格履歴 API |
| [privacy-consent-management.md](privacy-consent-management.md) | プライバシー同意管理の構築方法 |
| [product-catalog.md](product-catalog.md) | ハウツー: 商品カタログ API（ATK-01〜12） |
| [product-review-system.md](product-review-system.md) | 商品レビュー・評価システム |
| [project-task-management.md](project-task-management.md) | ハウツー: ネストされたリソースを使ったプロジェクト・タスク管理 |
| [quality-tools.md](quality-tools.md) | 品質ツール |
| [quota-management.md](quota-management.md) | ハウツー: クォータ管理 API |
| [rate-limiting.md](rate-limiting.md) | レート制限 |
| [rating-review-api.md](rating-review-api.md) | ハウツー: 評価・レビュー API |
| [rbac-jwt-auth.md](rbac-jwt-auth.md) | ハウツー: RBAC + JWT 認証 |
| [rbac.md](rbac.md) | ハウツー: ロールベースアクセス制御（RBAC） |
| [refresh-token-pattern.md](refresh-token-pattern.md) | ハウツー: リフレッシュトークンパターン |
| [refresh-token-rotation.md](refresh-token-rotation.md) | ハウツー: JWT リフレッシュトークンローテーション |
| [request-deduplication.md](request-deduplication.md) | リクエスト重複排除の追加方法 |
| [request-scoped-state.md](request-scoped-state.md) | ミドルウェアとハンドラー間でリクエストスコープの状態を渡す方法 |
| [reservation-availability-api.md](reservation-availability-api.md) | ハウツー: 予約・空き状況 API |
| [resource-booking.md](resource-booking.md) | ハウツー: リソース予約システム |
| [resource-reservation-booking.md](resource-reservation-booking.md) | ハウツー: リソース予約・ブッキング API |
| [resource-reservation.md](resource-reservation.md) | ハウツー: リソース予約 / タイムスロットブッキング API |
| [scheduled-publish-article.md](scheduled-publish-article.md) | ハウツー: 記事の予約公開 |
| [scheduled-reminders.md](scheduled-reminders.md) | ハウツー: スケジュールリマインダー API |
| [search-autocomplete.md](search-autocomplete.md) | 全文検索・オートコンプリート API の実装ガイド |
| [secret-vault.md](secret-vault.md) | ハウツー: パーソナルシークレットボールト API |
| [service-status-page.md](service-status-page.md) | ハウツー: サービスステータスページ API |
| [session-management.md](session-management.md) | ハウツー: マルチデバイスセッションマネージャーの構築 |
| [session-token-management.md](session-token-management.md) | ハウツー: セッション/トークン管理 API（ATK-01〜12） |
| [shift-management.md](shift-management.md) | ハウツー: シフト管理 API |
| [shopping-cart-api.md](shopping-cart-api.md) | ハウツー: ショッピングカート API |
| [signed-url-download.md](signed-url-download.md) | ハウツー: セキュアダウンロードのための署名付き URL |
| [signed-urls.md](signed-urls.md) | 署名付き URL |
| [sliding-window-rate-limiter.md](sliding-window-rate-limiter.md) | ハウツー: スライディングウィンドウレートリミッター |
| [slug-management.md](slug-management.md) | スラグ管理 — 衝突解決と履歴付きの一意な URL スラグ |
| [slug-url-history.md](slug-url-history.md) | ハウツー: 履歴付きのスラグ URL 管理 |
| [soft-delete-restore-permanent.md](soft-delete-restore-permanent.md) | ハウツー: ソフトデリート、リストア、完全削除 |
| [soft-delete-trash-purge.md](soft-delete-trash-purge.md) | ハウツー: ソフトデリート、ゴミ箱、完全パージ |
| [soft-delete-trash-restore.md](soft-delete-trash-restore.md) | ハウツー: ソフトデリート、ゴミ箱 & リストア API |
| [soft-delete.md](soft-delete.md) | ソフトデリート（論理削除） |
| [sql-injection-defence.md](sql-injection-defence.md) | ハウツー: SQL インジェクション防御 |
| [sql-injection.md](sql-injection.md) | SQL インジェクション防御 |
| [sql-orderby-injection.md](sql-orderby-injection.md) | SQL ORDER BY インジェクションの防止方法 |
| [sqlite-fts5-search.md](sqlite-fts5-search.md) | ハウツー: SQLite FTS5 全文検索 |
| [state-machine-audit-log.md](state-machine-audit-log.md) | ハウツー: 監査ログ付きステートマシン |
| [state-machine-workflow-api.md](state-machine-workflow-api.md) | ハウツー: ステートマシンワークフロー API |
| [step-workflow-approval.md](step-workflow-approval.md) | ハウツー: 承認付きステップベースワークフロー |
| [subscription-plan-management.md](subscription-plan-management.md) | ハウツー: サブスクリプションプラン管理 |
| [subscription-plan.md](subscription-plan.md) | ハウツー: サブスクリプション / プラン管理 API（VULN-A〜L） |
| [system-announcement-management.md](system-announcement-management.md) | ハウツー: システムアナウンス管理の構築 |
| [tag-label-api.md](tag-label-api.md) | ハウツー: タグ / ラベル API |
| [tagging-system.md](tagging-system.md) | タグ付けシステム（M:N） |
| [tenant-isolation-idor.md](tenant-isolation-idor.md) | ハウツー: テナント分離と IDOR 防止 |
| [tenant-isolation.md](tenant-isolation.md) | ハウツー: テナント分離とクロステナント IDOR 防止 |
| [threaded-comments-api.md](threaded-comments-api.md) | ハウツー: スレッド化コメント API |
| [threaded-comments.md](threaded-comments.md) | スレッド化コメント |
| [time-tracking.md](time-tracking.md) | ハウツー: タイムトラッキング API |
| [timezone-aware-scheduling.md](timezone-aware-scheduling.md) | ハウツー: タイムゾーン対応イベントスケジューリング |
| [token-lifecycle-api.md](token-lifecycle-api.md) | ハウツー: API トークンライフサイクル管理 |
| [totp-authentication.md](totp-authentication.md) | TOTP 二要素認証の実装ガイド |
| [transaction-scope-pattern.md](transaction-scope-pattern.md) | ハウツー: トランザクションスコープパターン |
| [transactions.md](transactions.md) | データベーストランザクション |
| [unicode-aware-text-api.md](unicode-aware-text-api.md) | ハウツー: Unicode 対応テキスト API |
| [upvote-downvote-api.md](upvote-downvote-api.md) | ハウツー: アップボート / ダウンボート API |
| [url-bookmark-api.md](url-bookmark-api.md) | ハウツー: タグフィルタリング付き URL ブックマーク API |
| [url-shortener-ssrf-prevention.md](url-shortener-ssrf-prevention.md) | ハウツー: SSRF 防止付き URL 短縮サービス |
| [url-shortener-ssrf.md](url-shortener-ssrf.md) | URL 短縮 API と SSRF 防止 |
| [use-bearer-auth.md](use-bearer-auth.md) | ハウツー: Bearer トークン認証の使用方法 |
| [use-fts5-search.md](use-fts5-search.md) | SQLite FTS5 全文検索の使用方法 |
| [use-postgresql.md](use-postgresql.md) | ハウツー: PostgreSQL の使用方法 |
| [use-transactions.md](use-transactions.md) | ハウツー: データベーストランザクションの使用方法 |
| [use-window-functions.md](use-window-functions.md) | ハウツー: SQLite ウィンドウ関数を使う |
| [user-follow-system.md](user-follow-system.md) | ハウツー: NENE2 でユーザーフォローシステムを構築する |
| [user-invitation.md](user-invitation.md) | ユーザー招待システム |
| [user-preferences-api.md](user-preferences-api.md) | ハウツー: ユーザープリファレンス API |
| [user-preferences-management.md](user-preferences-management.md) | User Preferences Management |
| [user-profile-api.md](user-profile-api.md) | ハウツー: ユーザープロフィール API |
| [user-profile-management.md](user-profile-management.md) | ユーザープロフィール管理 |
| [validate-unicode-input.md](validate-unicode-input.md) | ハウツー: Unicode 入力のバリデーション |
| [voting-system.md](voting-system.md) | 投票システム（アップボート / ダウンボート） |
| [waitlist-management.md](waitlist-management.md) | ウェイティングリスト管理 |
| [waitlist-system.md](waitlist-system.md) | ハウツー: ウェイトリストシステム |
| [webhook-delivery-api.md](webhook-delivery-api.md) | ハウツー: Webhook 配信 API |
| [webhook-delivery-system.md](webhook-delivery-system.md) | ハウツー: Webhook 配信システム |
| [webhook-delivery.md](webhook-delivery.md) | アウトバウンド Webhook 配信 |
| [webhook-signature-verification.md](webhook-signature-verification.md) | ハウツー: HMAC-SHA256 による Webhook 署名検証 |
| [webhook-signature.md](webhook-signature.md) | Webhook 署名検証 |
| [wish-list-api.md](wish-list-api.md) | ハウツー: ウィッシュリスト API（VULN-A〜L セキュリティアセスメント） |
| [wishlist-management.md](wishlist-management.md) | ウィッシュリスト管理 |

<!-- AUTO-INDEX:END -->
