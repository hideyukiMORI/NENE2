# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.5.59`（2026-05-21 リリース済み）
- Current branch: `main` — clean — open Issue なし

## Recently Completed (FT ループ — v1.5.27 〜 v1.5.39)

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

## 次のアクション

- FT ループ継続中（FT126 以降・次の脆弱性診断は FT126・次のクラッカー攻撃試験は FT128）

## Open Issues

なし

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- FT ループが毎回 main にマージするため、リリースのたびにこのファイルも更新すること。
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
