# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.5.44`（2026-05-21 リリース済み）
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

## 次のアクション

- FT ループ継続中（ScheduleWakeup 設定済み）

## Open Issues

なし（FT110 で起票した #722 は v1.5.44 で解消済み）

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- FT ループが毎回 main にマージするため、リリースのたびにこのファイルも更新すること。
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
