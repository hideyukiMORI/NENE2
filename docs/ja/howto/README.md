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
