# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- `Nene2\Auth\LocalBearerTokenVerifier` に任意の時刻源 `Nene2\Http\ClockInterface` を注入可能にする — コンストラクタ末尾に `ClockInterface $clock = new UtcClock()`（PHP 8.1+ new-in-initializer・既定は現行と同じ system/UTC clock）を**既定引数として加算**し、`verify()` は `now` を1回だけ読んで `exp`/`nbf` を注入 clock 由来の同一 instant で判定する（従来の `time()` 直書き2箇所を置換）。既定挙動は `UtcClock::now()->getTimestamp()` ＝ `time()` で**完全同値**、既存 caller `new LocalBearerTokenVerifier($secret)` は無改修＝**後方互換**（optional param 追加の非破壊加算・ADR 0009 公開サーフェス）。issuer `issue()` は呼び出し側が `exp`/`nbf` を計算して渡す設計のため、同一インスタンスに 1 個の clock を注入すれば発行/検証で時刻源が対称になり、固定時計で exp/nbf 境界を**決定論テスト**できる（field #19 の時刻源非対称による時限フレークの根治）。now を1回読みに変えたことで2回読みの秒跨ぎ競合も解消（#1506）
- 監査ログ基盤 `Nene2\Audit` — 複数製品（invoice/payout/profile/vault/clear）が同型に自作していた監査ログを framework の 1 モジュールに集約する（ADR 0014）。**公開安定 API**: `AuditEvent`（`final readonly` VO・種別 `action` は製品所有の free string で framework は enum を同梱しない・scalar id は `string|int|null` で auto-increment と ULID の両対応・`before`/`after`/`metadata`/`occurredAt` 受け皿付き）／`AuditQuery`（共通フィルタ VO・sort 列と方向をコンストラクタで**ホワイトリスト検証**し不正 sort を境界で拒否）／`AuditPayloadMode`（`BeforeAfter`＝canonical・`SinglePayload`＝過渡）／`AuditTableConfig`（既存テーブルへ**再 migration せず**向けるカラム/モード/id 型の写像・`canonical()` が収斂先）／契約 `AuditRecorderInterface`・`AuditRecorderFactoryInterface`（監査行を業務ミューテーションと同一 TX に束ねる `forExecutor()`＝invoice/payout の良形を既定化）・`AuditEventRepositoryInterface`（**append-only**: append/query/count・更新削除の経路なし）。既定実装 `AuditRecorder`（`occurredAt`↔`ClockInterface`／`organizationId`↔`RequestScopedHolder` を補完し、profile の repo 内 `date()` ドリフト・非原子を構造的に解消）・`AuditRecorderFactory`・`PdoAuditEventRepository`（生パラメタ化 SQL・`JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE`）と参照配線 `AuditServiceProvider` は**安定保証外**（`src/Example/` 同様 copy-and-adapt）。canonical 参照スキーマ `database/migrations/*_create_audit_events_table` ＋ `database/schema/audit_events.sql`（before/after・`metadata_json`・`occurred_at`・BIGINT autoinc 既定／ULID は config 対応）も安定保証外の参照。**製品の自作撤去・移行は別 PR**（今回はコア追加のみ・一覧 route/CSV export/actor_email join は製品の read 層に据え置き）（#1494）
- CSV 出力基盤 `Nene2\Export\CsvWriter` — 6 製品・9 サイトが `fputcsv` を手組みし、formula injection 対策・RFC4180 escape・BOM・streaming の 4 軸で品質がバラついていた CSV 出力を framework の 1 モジュールに集約する（ADR 0015）。**公開安定 API**: `CsvWriter`（コンストラクタ `$stream`／`$headers`／`$bom`／`$sanitizeFormulas`、`writeRow(array)`／`writeAll(iterable)`）。**formula injection 中和を既定 ON・型ベース**（先頭が `= + - @` TAB CR の**文字列**セルに `'` を付与し中和／`int`・`float`・`bool`・`null` は素通し＝負数 `-1200` は数値のまま・文字列 `"-1200"` は中和という判別が構造的に効く）。**RFC4180 quoting**（`fputcsv(..., escape: '')`＝enclosure を二重化・PHP 8.4 の非空 escape deprecation を回避。escape は私的定数で**非公開**＝consumer が legacy backslash escape に戻せない）。**BOM オプション**（既定 ON・Excel-JP 安全側・下流パーサ都合で無効化可）。**generator/iterable ストリーミング**（`writeAll(iterable)` が generator を受け O(batch) メモリ／BOM・ヘッダは初回書込で遅延出力し空 export もヘッダのみの正しい CSV になる）。叩き台は `nene-invoice/src/Support/Csv.php`（型ベース中和・数値保持）をフリート最良形として昇格。**6 製品・9 サイトの consumer 移行と真の streaming 化・BOM 全社統一は別 Issue**（今回はコア追加のみ・profile の銀行CSV **取込**は対象外＝出力のみ）（#1504）

---

## [1.7.0] — 2026-07-05

fail-closed な JWT secret 解決を framework 既定化するマイナーリリース。bearer トークンの HMAC secret 解決を一箇所（`GuardedJwtSecretResolver`・ハイブリッド dev 鍵モデル・ADR 0013）に集約し、Production では dev 鍵を絶対に採用しない・空 secret は未設定扱いで例外という fail-closed 挙動を framework の既定として提供する。あわせて Installer フィールドに入力型（`InstallerInputType` / `InstallerField`）を追加し、password 入力の平文再表示を防ぐ。いずれも公開 API への加算・後方互換で、製品側の自前ガード撤去は別 PR に分離する。

### Added
- fail-closed な JWT secret 解決の framework 既定 — `Nene2\Auth\GuardedJwtSecretResolver`（`final readonly`・公開安定 API）と `Nene2\Auth\JwtSecretException`（`RuntimeException` 継承・公開安定 API）。bearer トークンの HMAC secret は全 operator/service トークンを署名するため、推測可能な値は完全な認証バイパスになる。resolver は **ハイブリッド dev 鍵許可モデル**で解決する: 設定済み secret が非空なら全環境で採用（空文字＝未設定扱い）／`AppEnvironment::Production` は常に拒否（opt-in があっても dev 鍵を絶対使わない＝ハード fail）／local・test で明示 opt-in（`NENE2_ALLOW_DEV_SECRET` を strict に `1`/`true`/`yes`）かつ製品注入の dev 鍵があれば dev 鍵を採用／それ以外は例外。dev 鍵は framework が持たず製品が注入（`?string $devSecret`・null＝dev 経路無効）し、製品ごとの dev 鍵分離を保つ。`GuardedJwtSecretResolver::fromConfig(AppConfig, ?string): string` で `NENE2_LOCAL_JWT_SECRET` 経路を1行化。ADR 0013。**製品の自前ガード撤去は別 PR**（今回はコア追加のみ）（#1490）
- `AppConfig::$allowDevSecret`（型付き bool・既定 `false`）— `NENE2_ALLOW_DEV_SECRET` の strict 真偽（`1`/`true`/`yes` のみ真・typo は opted-out）を `ConfigLoader` が読み取り公開する。raw env 読取は `ConfigLoader` 内のみの規約を守り、resolver は env を直読みしない。コンストラクタ末尾に既定付きで追加＝**後方互換**（#1490）
- `Nene2\Install` のインストーラフィールドに入力型を追加 — `InstallerInputType`（`text` / `password`）と `InstallerField`（name + type、`InstallerField::text()` / `password()` ファクトリ）。`InstallerRenderer` が型に応じた `<input type="...">` を出力し、**password は送信値をページに反映しない**（平文の再表示を防ぐ）（#1482）

### Changed
- `InstallerStep::$inputs` が `list<string>` から正規化済み `list<InstallerField>` になった。**後方互換**: bare な文字列名は従来どおり text フィールドへ昇格するため、`new InstallerStep('database', ['db_name'])` は変わらず動作する。`$step->inputs` を直接読む場合のみ要素が `InstallerField` になる（新モジュール `Nene2\Install` は 1.6.0 で導入されたばかりで、唯一の consumer の採用と協調済み）（#1482）

---

## [1.6.0] — 2026-07-04

共有ホスティング向け **opt-in インストーラ toolkit `Nene2\Install`** を追加するマイナーリリース。製品ごとに巨大な install.php を複製せず、危険な核（zip 安全性・SHA-256/署名・.env 原子書込・DB migration・再インストール阻止・リリース取得）をフレームワークに一箇所だけ置き、各製品が再利用する（第1 consumer = NeNe Invoice #562）。すべて opt-in・generic — wire しなければ dormant、製品固有の前提（UI/ブランド/語彙/パス）は core に焼かない。

### Added
- `Nene2\Install` payload セキュリティ核 — `PayloadInstaller`（検証は必ず展開前・使い捨て staging→原子 swap）/ `ZipEntrySafety`（zip-slip・絶対パス・許可トップレベル検査）/ `PayloadSignatureVerifier`（SHA-256＋署名フック）（#1464）
- preflight & config — `ServerRequirementChecker`（+`ServerRequirements`/`RequirementCheck`/`SystemProbe`/`LiveSystemProbe`・診断のみ FS 非変更）/ `EnvironmentWriter`（.env 原子書込・phpdotenv 文法エスケープ・`generateSecret()`）/ `ReInstallationGuard`（+`ProvisioningProbe`・marker 短絡＋DB probe の二層）（#1466）
- `TenantConfigurationValidator`（+`TenantConfiguration`/`TenantConfigurationResult`）— テナント解決モード検証。共有 default なし・語彙と base domain 必須集合は製品が注入し、reason code で返す（#1468）
- `DatabaseSchemaApplier` — phinx Manager API による programmatic migration 適用（CLI 不可の共有ホスティング向け）。⚠️ 利用する consumer は `robmorgan/phinx` を **`require`**（not require-dev）に置くこと — `--no-dev` 本番 vendor でクラス不在のまま CI/dev だけ緑になる（#1470）
- release manifest 契約 — `ReleaseDescriptor` / `ReleaseManifestParser`（+`ReleaseManifestResult`）。origin `targets.schema.json` 逐語 parse・unknown schema major は reject（#1472）
- リリース取得 — `ReleaseSource` / `HttpReleaseSource` / `HttpTransport` / `CurlHttpTransport`（https 限定・artifact の temp download。検証/展開は PayloadInstaller の責務）（#1474）
- ウィザード提示契約 — `InstallerMessages` / `DefaultInstallerMessages`（無ブランド英文既定・reason code→文言）/ `InstallerStep` / `InstallerFlow`（baked flow なし・製品がステップを渡す）（#1476）
- 無ブランド参照 UI — `InstallerTemplate` / `InstallerRenderer` / `Html`（毎ステップ guard 評価・全 escape・error は reason-code のみ）（#1478）

---

## [1.5.333] — 2026-07-02

セキュリティ強化リリース。横断セキュリティ監査（コード5系統＋捨てコンテナ実機 ATK）で検出した全 EXPOSED を是正し、修正後の再テストで 0 EXPOSED を確認・文書化した。加えて再利用可能な TOTP プリミティブを追加。

### Added
- `Nene2\Auth\TotpAuthenticator` — RFC 6238 TOTP プリミティブ。Base32 シークレット生成 / `otpauth://` プロビジョニング URI 生成 / 時間ステップ計算 / 許容ウィンドウ付き定数時間検証（一致 time_step を返しリプレイ防止に使える）。digits/period/algorithm/window 設定可（#1427）。
- `Nene2\Auth\RecoveryCodes` — リカバリコードの生成・SHA-256 ハッシュ化・定数時間検証。書式/大小を正規化（#1427）。両クラスを ADR 0009 の安定 public API に追記。
- `SecurityHeadersMiddleware` に opt-in の `Strict-Transport-Security`（`$enableHsts` / `$hstsValue`）を追加。`RuntimeApplicationFactory` に `$enableHsts` を配線（既定 off・後方互換）（#1447）。
- `ResponseEmitter::emit()` に任意引数 `$requestMethod` を追加し、HEAD 応答の本文を抑制（RFC 7231 §4.3.2）（#1443）。
- `RequestSizeLimitMiddleware` に任意引数 `$streamFactory` を追加（サイズ不明ボディ計測後の再供給用）（#1444）。
- `LocalMcpServer` に任意の PSR-3 logger を追加し、状態変更ツール呼び出しを監査。`destructive` ツールは明示的な `confirm` を要求（#1446）。
- `docs/security/` — セキュリティ評価レポート（修正後の実機 ATK・回帰検証）と索引（#1459）。

### Changed
- `SecurityHeadersMiddleware` の既定 `Referrer-Policy` を `no-referrer-when-downgrade` → `strict-origin-when-cross-origin` に強化（#1447）。
- `RecoveryCodes::generate()` の既定エントロピーを 40bit → **80bit**（既定 `$bytes` 5→10）。無塩 SHA-256 でも DB 漏洩時のオフライン総当たりを非現実的にする（#1442）。
- example Note/Tag ハンドラ: title/name の最大長（255 文字）・body のバイト長（65535）・非 string 型を検証し、超過/不正型を 500 でなく 422 で拒否（#1450）。

### Fixed (Security)
- **HEAD による認証回避**: Router は HEAD を GET ハンドラで処理する一方 `ApiKeyAuthenticationMiddleware` が HEAD を別メソッド扱いし、`protectedMethods:['GET']` 構成で HEAD が認証をスキップして本文を返していた。HEAD を GET と同一に正規化して封鎖（#1443）。
- **サイズ制限回避**: `Content-Length` 欠如かつサイズ不明（chunked/`php://input`）のボディが `RequestSizeLimitMiddleware` を素通りしていた。読みながら上限で打ち切るよう修正（#1444）。
- **エラーログ衛生**: 未処理例外の完全なオブジェクト（`PDOException` の SQL・列名・DSN/host を含む）と生の `X-Request-Id` をログしていた。静的メッセージ＋sanitize 済み request id に修正、詳細は debug 時のみ（#1445）。
- 過大/不正型の入力が DB に到達して 500 を誘発していた問題を 422 で防止（#1450）。

### Security
- `undici`（high）/ `brace-expansion` を `npm audit fix` で解消（frontend の dev 依存）（#1448）。

### Docs
- 記事 frontmatter の YAML 不正で失敗していた VitePress(Docs) ビルドを修復（#1439）。DEV ポートフォリオ記事（#1436）・Zenn 設計メモ下書き（#1454）を追加。

---

## [1.5.332] — 2026-06-27

### Added
- `Nene2\Database\Preflight\ApplicationIdentity` / `ApplicationIdentityMarker` — アプリ識別マーカーの value object と書き込みパス（#1420 / B）。`stamp()` は `nene2_app_identity` テーブルを作成し単一行を冪等に書き込む。init/マイグレーション時、および既存 DB の backfill（採用時の一度きり）に使う。
- `Nene2\Database\Preflight\DefaultDatabaseCandidateInspector` — `applicationIdentity`（`?ApplicationIdentity`）と `identityTable`（既定 `nene2_app_identity`）コンストラクタ引数を追加。設定すると verdict の `app_identity`（`match` / `mismatch` / `absent`）と、マルチテナント identity では `tenant`（`match` / `mismatch`）を read-only で評価する。
- **既存 DB の fail-closed 回避**（#1420 の核心）: identity マーカー不在は `refuse` にせず `app_identity: absent` + `identity_unverified` を返し、マイグレーション由来の recommendation（`safe` / `needs_migration`）を維持する。`mismatch`（別アプリのマーカー）のみ自動 `refuse`。`tenant: mismatch` も自動 `refuse`。
- 識別マーカーテーブルは framework 内部テーブルとして扱い、`populated` / `foreign` 判定から除外する（マーカーのみの DB は `foreign` でなく `fresh`）。
- `docs/development/machine-database-preflight.md` — エンドポイント・identity マーカー・**既存 DB の backfill 手順**を記載。
- `docs/openapi/openapi.yaml` — `MachineDatabasePreflightResponse` の `app_identity` / `tenant` に enum を追加。

### Notes
- `applicationIdentity` 未設定時は A スコープの挙動を維持（`app_identity: not_evaluated` / `tenant: not_applicable` + 旧マルチテナントガード `tenant_unevaluated`）。後方互換。

---

## [1.5.331] — 2026-06-27

### Added
- `Nene2\Database\Preflight` — 候補データベースを **read-only で自己診断**する framework 機能の MVP（#1419 / A）。`DatabaseCandidateInspector` インターフェース、無設定で動く `DefaultDatabaseCandidateInspector`（マイグレーション台帳 `phinx_log` ベース）、`CandidateProfile` / `PreflightVerdict` value object、`MigrationState` / `PreflightRecommendation` enum を追加。
- `Nene2\Http\RuntimeApplicationFactory` — `databaseCandidateInspector`（`?DatabaseCandidateInspector`、既定 `null`）と `databaseCandidateProfiles`（`array<string, CandidateProfile>`、既定 `[]`）コンストラクタ引数を追加。inspector を渡したときのみ `POST /machine/database/preflight` を登録する。`null` のままなら framework コアは従来どおり DB 非依存で、エンドポイントは生えない（後方互換）。エンドポイントは machine API キーで自動保護される（allowlist モード時）。
- エンドポイントは候補プロファイル **ID のみ**を受け取り、接続情報・認証情報はアプリ自身の設定から解決する（DSN/creds を wire に載せない・SSRF 封じ）。verdict は reason code のみで生の DB 名/ホスト/データを含まない。read-only はトランザクション機構で保証（SQLite `PRAGMA query_only`、MySQL/PostgreSQL `START TRANSACTION READ ONLY`）。
- マルチテナント構成のガード（#1419）: tenant 未評価（B / #1420 未導入）の間は無条件 `safe` を返さず、`reason_codes` に `tenant_unevaluated` を付けて `needs_review` に倒す。`app_identity` は `not_evaluated`、`tenant` は `not_applicable`（いずれも #1420 で有効化）。
- `docs/openapi/openapi.yaml` — `machineDatabasePreflight` 操作、`MachineDatabasePreflightRequest` / `MachineDatabasePreflightResponse` スキーマを追加。
- `docs/mcp/tools.json` — `machineDatabasePreflight` ツール（`safety: read`）を追加。

---

## [1.5.330] — 2026-06-25

### Added
- `Nene2\Http\RuntimeApplicationFactory` — `appVersion` コンストラクタ引数（`?string`、既定 `null`）を追加。設定すると `GET /machine/health` のレスポンスに消費側アプリ自身の semver を `version` フィールドとして返す。`null` の場合は `version` を省略するため後方互換。アプリ版（`version`）はフレームワーク版とは別物で、フレームワーク版は常に `framework_version`（= `FrameworkInfo::VERSION`）として返す。machine クライアント（監視・運用・デプロイツール等）が公開 `/health` に版情報を晒さずにアプリ版を読めるよう、認証済みの machine エンドポイントに載せた（#1414）
- `docs/openapi/openapi.yaml` — `MachineHealthResponse` に `version`（任意）と `framework_version`（必須）を追記。`withAppVersion` example を追加

### Changed
- `docs/development/machine-client-smoke.md` — `/machine/health` のレスポンス例を新フィールド（`framework_version` / 任意の `version`）に合わせて更新

---

## [1.5.329] — 2026-05-31

### Fixed
- `docker/php/Dockerfile` — `output_buffering=4096` / `display_errors=Off` / `log_errors=On` / `expose_php=Off` を追加。PHP 起動時 Warning がレスポンスに漏洩しセキュリティヘッダーを無効化していた問題（FIND-01 / #1361）
- `docker/php/Dockerfile` — `ServerTokens Prod` / `ServerSignature Off` / `Header always unset X-Powered-By` を Apache に追加。サーバー構成情報（Apache バージョン・PHP バージョン）がレスポンスヘッダーに露出していた問題（FIND-03 / #1361）
- `src/Auth/LocalBearerTokenVerifier` — `exp` クレームの存在と整数型を必須化。`exp` なし JWT が永久に有効だった問題（FIND-02 / #1361）
- `public_html/openapi.php` — `APP_ENV=production` 時に 404 を返すガードを追加。本番環境で全ルート・スキーマ・バージョン情報が認証不要で取得できた問題（FIND-04 / #1361）
- `src/Database/PdoConnectionFactory` — MySQL / PostgreSQL DSN に `connect_timeout=3` を追加。DB 未起動時のルート存在タイミングオラクル（有効ルートの列挙）を緩和（FIND-05 / #1361）
- `docs/openapi/openapi.yaml` — `servers[0].url` を `http://localhost:8080` → `http://localhost:8200` に更新（#1361）

---

## [1.5.328] — 2026-05-30

### Fixed
- `Nene2\Http\RuntimeApplicationFactory` — framework レベルの Problem Details（`validation-failed` / 404 / 405 / 413 / 500 等）が `PROBLEM_DETAILS_BASE_URL` を無視して常に `https://nene2.dev/problems/` を返していたバグを修正。`$problemDetailsBaseUrl` コンストラクタ引数（既定 `https://nene2.dev/problems/`）を追加し、内部の `ProblemDetailsResponseFactory` へ配線。consumer は `AppConfig::$problemDetailsBaseUrl` を渡すだけで framework / domain 双方の Problem `type` namespace が揃う。既定値は据え置きのため既存 consumer は無変更で従来動作（#1355）

---

## [1.5.327] — 2026-05-29

### Added
- `Nene2\Testing\DatabaseTestKit` — テスト用 DB 配線ヘルパー（stable public API）。`PdoConnectionFactory` / `PdoDatabaseQueryExecutor` / `PdoDatabaseTransactionManager` をまとめて配線し、3 つのインターフェースを readonly コンストラクタプロパティで公開。`DatabaseTestKit::sqlite($path)` は `:memory:` を明示的に拒否し、`transactional()` 非互換罠（IMP-18）を構造的に塞ぐ。`fromConfig(DatabaseConfig)` で任意 adapter にも対応（IMP-14 / ADR 0012 / #1307）
- `docs/adr/0012-sanctioned-test-database-wiring.md` — `Nene2\Testing` 名前空間導入の決定記録
- `Nene2\Config\DatabaseConfig::sqlite(string $path, string $environment = 'local')` — テスト・小規模スクリプト向けの SQLite 設定ファクトリ。9 引数コンストラクタを 1 行に短縮（IMP-04 / #1303）

### Changed
- `docs/adr/0009-v1.0-public-api-scope.md` — stable public API 表に `Nene2\Testing\DatabaseTestKit` 行を追加

### Fixed
- `Nene2\Validation\V::isoDatetime()` — 範囲外の UTC オフセット（`+25:00` / `+99:00` 等、正規表現が時間部 00–99 を通すため）を許容していたバグを修正。絶対値が `±14:00` を超えるオフセットを拒否（実在 TZ は全て範囲内）。分 ≥ 60 は従来どおり round-trip で拒否（#1351）
- `Nene2\Validation\V::futureDatetime()` — ISO 文字列の字句比較で未来判定していたため、`$raw` と `$now` の TZ オフセットが異なると瞬時の前後が逆転しても誤判定するバグを修正。`DateTimeImmutable` の instant 比較に変更し、異なるオフセット間でも正しく比較。`$now` がパース不能な場合は `null` を返す（#1351）

---

## [1.5.326] — 2026-05-29

### Added
- `docs/howto/bulk-reorder-api.md` — FT352 reorderlog (ATK) 新規作成: 一括並べ替え（drag-and-drop ordering）API: `CASE WHEN` 単文 UPDATE による原子的 reorder・board スコープ・affected 件数検証・ATK-01〜12 攻撃試験 (#1343)

---

## [1.5.325] — 2026-05-29

### Added
- `docs/howto/csv-export-formula-injection.md` — FT351 csvexport (VULN) 新規作成: CSV/表計算エクスポートの数式インジェクション防止 howto: 先頭 `= + - @` タブ/CR の中和・RFC 4180 クォート・Content-Disposition ファイル名インジェクション・V-01〜V-10 脆弱性診断 (#1339)

---

## [1.5.324] — 2026-05-29

### Added
- `docs/howto/use-window-functions.md` — FT350 windowfunc 新規作成: SQLite ウィンドウ関数 howto: ROW_NUMBER/RANK/DENSE_RANK ランキング・SUM OVER 移動合計・LAG/LEAD 期間比較・top-N-per-group CTE・readonly DTO マッピング (#1337)

---

## [1.5.323] — 2026-05-27

### Added
- `docs/howto/event-sourcing-cqrs-api.md` — eventstore 新規作成: イベントソーシング / CQRS howto: append-only ログ・per-aggregate シーケンス・read-model projection (#1265)
- `docs/howto/soft-delete-restore-permanent.md` — softdelete 新規作成: ソフトデリート / リストア / 永続削除 howto (#1265)

### Changed
- `tools/uncovered-fts.sh` — i18nlog / eventstore / projtrack / softdelete を検出対象に追加（全 FT カバー確認）(#1265)

---

## [1.5.322] — 2026-05-27

### Changed
- `docs/todo/current.md` — FT349 完了・全 FT カバー達成・引き継ぎ状態更新 (#1263)

---

## [1.5.321] — 2026-05-27

### Changed
- `docs/howto/hierarchical-data.md` — FT171 (hierarchylog) NENE2-FT マーカー追加 (#1261)

### Added
- `docs/howto/game-score-leaderboard-api.md` — FT259 新規作成: ゲームスコア API howto: 単一提出・bulk 提出・leaderboard (best_score RANK・play_count) (#1261)

---

## [1.5.320] — 2026-05-27

### Added
- `docs/howto/state-machine-workflow-api.md` — FT349 新規作成: ステートマシン型ワークフロー API howto: 遷移マップ・allowed_next・history ログ・ターミナル状態 (#1259)

---

## [1.5.319] — 2026-05-27

### Added
- `docs/howto/webhook-delivery-api.md` — FT348 新規作成: Webhook 配信 API howto: 登録・イベント dispatch・配信ログ・リトライ・ATK アセスメント付き (#1257)

---

## [1.5.318] — 2026-05-27

### Added
- `docs/howto/upvote-downvote-api.md` — FT347 新規作成: 賛否投票 API howto: UNIQUE(user_id, item_id) 1 票制限・トグルオフ・方向変更・score 集計 (#1255)

---

## [1.5.317] — 2026-05-27

### Added
- `docs/howto/api-versioning.md` — FT346 新規作成: API バージョニング howto: URL パスバージョニング・V1 Deprecation ヘッダー・V2 data+meta ラッパー・共有ストレージ (#1253)

---

## [1.5.316] — 2026-05-27

### Added
- `docs/howto/unicode-aware-text-api.md` — FT345 新規作成: Unicode 対応テキスト API howto: mb_strlen による文字数カウント・Null バイト拒否・多言語スクリプト対応・VULN アセスメント付き (#1251)

---

## [1.5.315] — 2026-05-27

### Added
- `docs/howto/category-hierarchy-api.md` — FT344 新規作成: カテゴリ階層ツリー API howto: parent_id+depth 管理・再帰 CTE (ancestors/descendants)・子持ち削除禁止・ATK アセスメント付き (#1249)

---

## [1.5.314] — 2026-05-27

### Added
- `docs/howto/threaded-comments-api.md` — FT343 新規作成: スレッドコメント API howto: 2 階層スレッド・トゥームストーン削除・返信深度制限・削除後返信ブロック (FT343)。 (#1247)

---

## [1.5.313] — 2026-05-27

### Added
- `docs/howto/jwt-tenant-isolation.md` — FT342 新規作成: JWT マルチテナント隔離 howto: tenant_id JWT クレーム・テナントスコープクエリ・クロステナント 404・テナント ID 非公開 (FT342)。 (#1245)

---

## [1.5.312] — 2026-05-27

### Added
- `docs/howto/dynamic-sort-order-injection.md` — FT341 新規作成: ORDER BY インジェクション防止 howto: allowlist バリデーション・ステータスフィルター allowlist・ReDoS 耐性・ケースセンシティブ検証 (FT341)。 (#1243)

---

## [1.5.311] — 2026-05-27

### Added
- `docs/howto/soft-delete-trash-restore.md` — FT340 ATK 新規作成: ソフトデリート・ゴミ箱・復元 API howto: 削除後非表示・ゴミ箱一覧・完全削除・一括パージ・ATK アセスメント (FT340)。 (#1241)

---

## [1.5.310] — 2026-05-27

### Added
- `docs/howto/slug-url-history.md` — FT339 VULN 新規作成: スラグ URL 管理 howto: タイトルから自動生成・衝突時連番・旧スラグ 301 リダイレクト・スラグ履歴・VULN アセスメント (FT339)。 (#1239)

---

## [1.5.309] — 2026-05-27

### Added
- `docs/howto/signed-url-download.md` — FT338 新規作成: 署名付き URL ダウンロード howto: HMAC-SHA256 トークン・TTL・改ざん検知 401・期限切れ 410・リソース束縛 (FT338)。 (#1237)

---

## [1.5.308] — 2026-05-27

### Added
- `docs/howto/url-shortener-ssrf-prevention.md` — FT337 新規作成: URL 短縮 SSRF 防止 howto: プライベート IP ブロック・スキーム制限・slug バリデーション・mass assignment 防止・ISO 8601 日付検証 (FT337)。 (#1235)

---

## [1.5.307] — 2026-05-27

### Added
- `docs/howto/reservation-availability-api.md` — FT336 ATK 新規作成: 予約・空き状況 API howto: 半開区間重複防止・キャンセル後再予約・空き状況フィルター・ATK アセスメント (FT336)。 (#1233)

---

## [1.5.306] — 2026-05-27

### Added
- `docs/howto/resource-reservation-booking.md` — FT335 新規作成: リソース予約 API howto: 時間スロット重複防止・半開区間・user_id 非公開・キャンセル IDOR 403・管理者 API (FT335)。 (#1231)

---

## [1.5.305] — 2026-05-27

### Added
- `docs/howto/article-relations-api.md` — FT334 新規作成: 記事リレーション API howto: 双方向/非対称リレーション・自動逆関係挿入・タイプフィルター・削除時逆関係同時削除 (FT334)。 (#1229)

---

## [1.5.304] — 2026-05-27

### Added
- `docs/howto/rating-review-api.md` — FT333 VULN 新規作成: 評価・レーティング API howto: PUT upsert・スコア 1-5 バリデーション・サマリー分布・VULN アセスメント (FT333)。 (#1227)

---

## [1.5.303] — 2026-05-27

### Added
- `docs/howto/leaderboard-ranking-api.md` — FT332 ATK 新規作成: リーダーボードランキング API howto: スコア提出・個人ベスト管理・降順ランキング・自分のランク取得・ATK アセスメント (FT332)。 (#1225)

---

## [1.5.302] — 2026-05-27

### Added
- `docs/howto/password-auth-argon2id.md` — FT331 新規作成: パスワード認証 Argon2id howto: ユーザー登録・Argon2id ハッシュ・パスワード/ハッシュ非返却・ユーザー列挙防止 (FT331)。 (#1223)

---

## [1.5.301] — 2026-05-27

### Added
- `docs/howto/scheduled-publish-article.md` — FT330 新規作成: 予約公開記事管理 API howto: draft/published/archived 状態遷移・未来日時スケジュール・公開記事認証なし閲覧・下書き所有者のみ (FT330)。 (#1221)

---

## [1.5.300] — 2026-05-27

### Added
- `docs/howto/user-preferences-api.md` — FT329 新規作成: ユーザー設定管理 API howto: 5キー設定・デフォルト値・値バリデーション・unknown key 422+valid_keys・403 他ユーザー (FT329)。 (#1219)

---

## [1.5.299] — 2026-05-27

### Added
- `docs/howto/subscription-plan-management.md` — FT328 ATK 新規作成: プランサブスクリプション管理 API howto: プラン一覧・サブスクリプション作成/変更/キャンセル・403 他ユーザー操作防止・ATK アセスメント (FT328)。 (#1217)

---

## [1.5.298] — 2026-05-27

### Added
- `docs/howto/pin-bookmark-ordering.md` — FT327 VULN 新規作成: 記事ピン・順序管理 API howto: 最大10件制限・削除後再採番・順序変更・ユーザー分離・VULN アセスメント (FT327)。 (#1215)

---

## [1.5.297] — 2026-05-27

### Added
- `docs/howto/patch-partial-update.md` — FT326 新規作成: JSON Merge Patch 部分更新 API howto: PATCH 部分更新・null フィールドリセット・イミュータブルフィールド拒否・ETag・所有者認証 (FT326)。 (#1213)

---

## [1.5.296] — 2026-05-27

### Added
- `docs/howto/offset-cursor-pagination.md` — FT325 新規作成: オフセット・カーソルページネーション howto: next_offset/next_cursor・has_more・カテゴリフィルター (FT325)。 (#1211)

---

## [1.5.295] — 2026-05-27

### Added
- `docs/howto/optimistic-lock-patch-version.md` — FT324 ATK 新規作成: PATCH バージョン楽観的ロック howto: 409 current_version 返却・文字列 version 拒否・再試行パターン・ATK アセスメント (FT324)。 (#1209)

---

## [1.5.294] — 2026-05-27

### Added
- `docs/howto/optimistic-concurrency-version.md` — FT323 新規作成: 楽観的同時書き込み制御（バージョンフィールド）howto: PUT ボディ version・409 スレートバージョン・ロストアップデート防止 (FT323)。 (#1207)

---

## [1.5.293] — 2026-05-27

### Added
- `docs/howto/nested-json-validation.md` — FT322 新規作成: ネスト JSON バリデーション howto: items.N.field エラーパス・複数エラー一括返却・エラーコード・合計金額計算 (FT322)。 (#1205)

---

## [1.5.292] — 2026-05-27

### Added
- `docs/howto/api-usage-metering.md` — FT321 VULN 新規作成: API 使用量メタリング・クォータ管理 howto: 管理者クォータ設定・マシンキー使用量記録・日次ブレークダウン・IDOR 保護・VULN アセスメント (FT321)。 (#1203)

---

## [1.5.291] — 2026-05-27

### Added
- `docs/howto/optimistic-locking-etag.md` — FT320 ATK 新規作成: 楽観的ロック ETag/If-Match howto: バージョニング・If-Match 必須 428・スレート 412・ロストアップデート防止・ATK アセスメント (FT320)。 (#1201)

---

## [1.5.290] — 2026-05-27

### Added
- `docs/howto/pagination-limit-injection.md` — FT319 新規作成: ページネーション境界・Limit インジェクション防止 howto: MAX_LIMIT 強制・ctype_digit 型検証・オフセット/カーソルページネーション・ReDoS 安全 (FT319)。 (#1199)

---

## [1.5.289] — 2026-05-27

### Added
- `docs/howto/tenant-isolation-idor.md` — FT318 新規作成: テナント分離・IDOR 防止 API howto: X-Tenant-Id/X-User-Id 認証・クロステナント 404・ボディ tenant_id 無視・ヘッダー型検証・クエリパラメータ整数検証 (FT318)。 (#1197)

---

## [1.5.288] — 2026-05-27

### Added
- `docs/howto/inbound-webhook-gateway.md` — FT317 新規作成: 受信 Webhook ゲートウェイ API howto: HMAC-SHA256 署名検証・ソース管理・重複 event_id 冪等処理・シークレット非公開 (FT317)。 (#1195)

---

## [1.5.287] — 2026-05-27

### Added
- `docs/howto/idempotency-key-api.md` — FT316 ATK 新規作成: 冪等キー支払い API howto: X-Idempotency-Key ヘッダー・SHA-256 ハッシュ保存・X-Idempotent-Replayed ヘッダー・重複決済防止・ATK アセスメント (FT316)。 (#1193)

---

## [1.5.286] — 2026-05-27

### Added
- `docs/howto/category-hierarchy-api.md` — FT315 VULN 新規作成: 階層カテゴリ API howto: マテリアライズドパス・深さ制限・循環参照防止・subtree クエリ・祖先取得・VULN アセスメント (FT315)。 (#1190)

---

## [1.5.285] — 2026-05-27

### Added
- `docs/howto/follow-api.md` — FT314 新規作成: フォロー/アンフォロー API howto: 冪等フォロー・自己フォロー防止・followers_count/following_count stats・followers/following リスト降順・is-following チェック・相互フォロー (FT314)。 (#1188)

---

## [1.5.284] — 2026-05-27

### Added
- `docs/howto/feature-flag-api.md` — FT313 新規作成: フィーチャーフラグ API howto: 環境別フラグ管理・rollout_percent 段階的ロールアウト・ユーザー上書き override・evaluate エンドポイント・snake_case キーバリデーション (FT313)。 (#1186)

---

## [1.5.283] — 2026-05-27

### Added
- `docs/howto/data-export-api.md` — FT312 新規作成: データエクスポート API howto: 非同期 pending→ready パターン・PII 除外 toPublicArray・ARGON2ID パスワードハッシュ・410 期限切れ・409 未完了ダウンロード防止 (FT312)。 (#1184)

---

## [1.5.282] — 2026-05-27

### Added
- `docs/howto/expense-tracking-api.md` — FT311 新規作成: 経費管理 API howto: YYYY-MM-DD 日付バリデーション・カテゴリフィルタ・月次サマリ集計・ページネーション・PATCH 部分更新 (FT311)。 (#1182)

---

## [1.5.281] — 2026-05-27

### Added
- `docs/howto/event-sourcing-ledger.md` — FT310 新規作成: イベントソーシング口座台帳 howto: 不変イベントログ・replayBalance で残高再生・deposit/withdraw イベント追記のみ・上限 1000000000 (FT310)。 (#1180)

---

## [1.5.280] — 2026-05-27

### Added
- `docs/howto/magic-link-authentication.md` — FT309 新規作成: マジックリンク認証 VULN howto: トークン SHA-256 ハッシュ保存・TTL 15分・使用済み再利用防止・セッション失効・ユーザー列挙 202 防止・VULN-A〜L 全 SAFE (FT309)。 (#1178)

---

## [1.5.279] — 2026-05-27

### Added
- `docs/howto/webhook-delivery-system.md` — FT308 新規作成: Webhook 配信システム howto: SSRF防止 UrlValidator・HTTPS 強制・プライベート IP ブロック・HMAC-SHA256+タイムスタンプ署名・秘密鍵 SHA-256 ハッシュ保存・ATK-01〜12 全 BLOCKED (FT308)。 (#1176)

---

## [1.5.278] — 2026-05-27

### Added
- `docs/howto/etag-conditional-requests.md` — FT307 新規作成: ETag 条件付きリクエスト howto: If-None-Match→304・If-Modified-Since→304・If-Match→412/428・wildcard * 対応 (FT307)。 (#1174)

---

## [1.5.277] — 2026-05-27

### Added
- `docs/howto/emoji-reactions-api.md` — FT306 新規作成: 絵文字リアクション API howto: UNIQUE(post_id,user_id,emoji)・mb_strlen 上限・urldecode パス・user_reactions 取得者別表示 (FT306)。 (#1172)

---

## [1.5.276] — 2026-05-27

### Added
- `docs/howto/draft-publish-workflow.md` — FT305 新規作成: 記事下書き・公開・アーカイブ状態機械 howto: draft→published→archived 一方向遷移・著者専用書き込み・公開済みは閲覧可 (FT305)。 (#1170)

---

## [1.5.275] — 2026-05-27

### Added
- `docs/howto/flash-sale-api.md` — FT304 新規作成: フラッシュセール API howto: 時間窓バリデーション・在庫 UNIQUE(sale_id,user_id) 二重購入防止・売り切れ 422・ATK-01〜12 全 BLOCKED (FT304)。 (#1168)

---

## [1.5.274] — 2026-05-27

### Added
- `docs/howto/file-sharing-api.md` — FT303 新規作成: ファイル共有 API howto: プライベート 404 存在秘匿・所有者専用書き込み・共有許可モデル・VULN-A〜L 全 SAFE (FT303)。 (#1166)

---

## [1.5.273] — 2026-05-27

### Added
- `docs/howto/coupon-discount-api.md` — FT302 新規作成: クーポン割引コード API howto: 管理者専用作成・CODE_PATTERN 正規表現・期限切れ/上限超過/重複 409・UNIQUE(coupon_id,user_id) べき等 (FT302)。 (#1164)

---

## [1.5.272] — 2026-05-27

### Added
- `docs/howto/content-negotiation-api.md` — FT301 新規作成: JSON API コンテントネゴシエーション howto: Accept ヘッダー処理・application/json 強制・415 Content-Type ガード・Problem Details (FT301)。 (#1162)

---

## [1.5.271] — 2026-05-27

### Added
- `docs/howto/point-ledger-api.md` — FT300 新規作成: ポイント台帳 API howto: earn/spend/adjust/expire・overdraft防止・管理者専用adjust・reference_id べき等性・ATK-01〜12 全 BLOCKED (FT300)。 (#1160)

---

## [1.5.270] — 2026-05-27

### Added
- `docs/howto/collection-api.md` — FT299 新規作成: 記事コレクション API howto: is_public 公開制御・UNIQUE(collection_id,article_id)・position フィールド・404 存在秘匿 (FT299)。 (#1158)

---

## [1.5.269] — 2026-05-27

### Added
- `docs/howto/circuit-breaker.md` — FT298 新規作成: サーキットブレーカー howto: closed/open/half_open 3状態・失敗閾値・タイムアウト後自動 half_open 遷移・503 ブロック (FT298)。 (#1156)

---

## [1.5.268] — 2026-05-27

### Added
- `docs/howto/pii-masking.md` — FT297 新規作成: PII マスキング VULN howto: email/phone/name 部分マスク・admin 生データアクセス + X-Accessor 監査・VULN-A〜L 全 SAFE (FT297)。 (#1154)

---

## [1.5.267] — 2026-05-27

### Added
- `docs/howto/geolocation-api.md` — FT296 新規作成: 位置情報 API ATK howto: Haversine 距離・緯度経度バリデーション・近傍検索・bbox・半径クランプ・ATK-01〜12 (FT296)。 (#1152)

---

## [1.5.266] — 2026-05-27

### Added
- `docs/howto/bookmark-api.md` — FT295 新規作成: ブックマーク API howto: UNIQUE(user_id,item_id)・コレクション分類・重複 409・ユーザースコープ IDOR 防止 (FT295)。 (#1150)

---

## [1.5.265] — 2026-05-27

### Added
- `docs/howto/batch-api-partial-success.md` — FT294 新規作成: バッチ API 部分成功パターン howto: MAX_BATCH=50・各行独立バリデーション・created/errors 混合返却・DB CHECK 制約 (FT294)。 (#1148)

---

## [1.5.264] — 2026-05-27

### Added
- `docs/howto/ab-testing.md` — FT293 新規作成: A/B テスト実験フレームワーク howto: 重み付き確定的バリアント割当・draft→active→stopped ステートマシン・冪等割当 (FT293)。 (#1146)

---

## [1.5.263] — 2026-05-27

### Added
- `docs/howto/idempotency-key.md` — FT292 新規作成: 冪等性キー重複排除 ATK howto: UNIQUE(idempotency_key)・TTL 期限後再処理・replayed フラグ・ATK-01〜12 (FT292)。 (#1144)

---

## [1.5.262] — 2026-05-27

### Added
- `docs/howto/group-member-management.md` — FT291 新規作成: グループメンバー管理 VULN howto: MemberRole enum・UNIQUE(group_id, user_id)・オーナー削除防止・クロスグループ IDOR ブロック・VULN-A〜L (FT291)。 (#1142)

---

## [1.5.261] — 2026-05-27

### Added
- `docs/howto/otp-authentication.md` — FT290 新規作成: OTP 認証システム howto: 6桁数字 OTP + SHA-256 ハッシュ・brute-force ロックアウト・リプレイ攻撃防止・セッショントークン・user enumeration 防止・ATK-01〜ATK-12 (FT290)。 (#1140)

---

## [1.5.260] — 2026-05-27

### Added
- `docs/howto/content-reporting.md` — FT289 新規作成: コンテンツ報告システム howto: 報告理由 enum・UNIQUE 重複防止（冪等 200）・pending→resolved/dismissed 状態マシン・モデレーター専用 API・CHECK 制約 (FT289)。 (#1138)

---

## [1.5.259] — 2026-05-27

### Added
- `docs/howto/distributed-lock.md` — FT288 新規作成: 分散ロック howto と ATK 攻撃試験 (ATK-01〜ATK-12): UNIQUE(resource)・owner 所有権チェック・TTL 有効期限・期限切れ再取得・ReleaseResult enum・owner ミスマッチ 403 (FT288)。 (#1136)

---

## [1.5.258] — 2026-05-27

### Added
- `docs/howto/waitlist-system.md` — FT287 新規作成: ウェイトリストシステム howto: UNIQUE(user_id)・waiting/approved/declined 状態マシン・isTerminal()・/me ルート優先登録・管理者 X-Admin-Key・位置番号追跡 (FT287)。 (#1134)

---

## [1.5.257] — 2026-05-27

### Added
- `docs/howto/timezone-aware-scheduling.md` — FT286 新規作成: タイムゾーン対応スケジューリング howto: UTC 保存 + ローカル時刻変換・InvalidTimezoneException・?timezone 動的変換・DateTimeImmutable + DateTimeZone パターン (FT286)。 (#1132)

---

## [1.5.256] — 2026-05-27

### Added
- `docs/howto/password-reset-flow.md` — FT285 新規作成: パスワードリセットフロー howto と VULN 脆弱性診断 (V-01〜V-10): ユーザー列挙防止 (202)・SHA-256 ハッシュ保存・1時間 TTL・単一使用・410 Gone・Argon2id (FT285)。 (#1130)

---

## [1.5.255] — 2026-05-27

### Added
- `docs/howto/rate-limiting.md` — FT284 新規作成: ThrottleMiddleware レート制限 howto と ATK 攻撃試験 (ATK-01〜ATK-12): IP ベース・カスタム key extractor・X-RateLimit-* ヘッダー・429 Problem Details・Retry-After (FT284)。 (#1128)

---

## [1.5.254] — 2026-05-27

### Added
- `docs/howto/invitation-system.md` — FT283 新規作成: 招待コードシステム howto: 32文字 hex トークン (128bit エントロピー)・ISO 8601 日時バリデーション・pending→used 状態管理・match 式による状態マッピング・IDOR 保護 (FT283)。 (#1126)

---

## [1.5.253] — 2026-05-27

### Added
- `docs/howto/delegated-access-grants.md` — FT282 新規作成: 委任アクセス許可 (Delegated Access Grants) howto: read/write/admin スコープ・30日最大 TTL・UNIQUE 重複防止・CHECK 自己グラント禁止・IDOR 404・使用カウンタ (FT282)。 (#1124)

---

## [1.5.252] — 2026-05-27

### Added
- `docs/howto/refresh-token-pattern.md` — FT281 新規作成: リフレッシュトークンパターン howto: 短命アクセストークン (5分) + 長命リフレッシュトークン・SHA-256 ハッシュ保存・トークンローテーション・リプレイ攻撃検知・ログアウト 204 (FT281)。 (#1122)

---

## [1.5.251] — 2026-05-27

### Added
- `docs/howto/account-lockout.md` — FT280 新規作成: アカウントロックアウト howto と ATK 攻撃試験 (ATK-01〜ATK-12): 5回失敗→15分ロック・423 Locked・成功でリセット・ロックアウト状態エンドポイント (FT280)。 (#1120)

---

## [1.5.250] — 2026-05-27

### Added
- `docs/howto/rbac-jwt-auth.md` — FT279 新規作成: RBAC + JWT 認証 howto と VULN 脆弱性診断 (V-01〜V-10): Argon2id タイミング攻撃対策・JWT role クレーム・401 vs 403 区別・BearerTokenMiddleware・管理者専用エンドポイント保護 (FT279)。 (#1118)

---

## [1.5.249] — 2026-05-27

### Changed
- `docs/howto/direct-messaging-system.md` — FT278 参照と What NOT to do セクションを追加: conversations UNIQUE(initiator_id, recipient_id)・CHECK(initiator_id != recipient_id)・参加者のみアクセス可・方向非依存 conversation lookup (FT278)。 (#1116)

---

## [1.5.248] — 2026-05-27

### Changed
- `docs/howto/activity-feed.md` — FT277 参照と What NOT to do セクションを追加: type allowlist 9種・payload JSON TEXT・user_id インデックス (user_id, id DESC)・limit/offset クランプ・IDOR → 404・admin fail-closed (FT277)。 (#1114)

---

## [1.5.247] — 2026-05-27

### Changed
- `docs/howto/idempotency.md` — FT276 ATK クラッカー攻撃試験を追加: Idempotency-Key 必須チェック・リプレイ時ボディ変更無視・UNIQUE 制約レース条件・空キー拒否・負数/ゼロ数量拒否・クロスオリジンは JSON API では CSRF リスク低、ATK-01〜12 全 BLOCKED (FT276/ATK)。 (#1112)

---

## [1.5.246] — 2026-05-27

### Added
- `docs/howto/user-profile-api.md` — FT275: ユーザープロフィール API: 1ユーザー1プロフィール (UNIQUE)・email FILTER_VALIDATE_EMAIL・display_name 100文字/bio 500文字/avatar_url 2048文字制限・https URL のみ許可・DatabaseConstraintException → 409・所有者チェック (actorId=userId) (FT275)。 (#1110)

---

## [1.5.245] — 2026-05-27

### Changed
- `docs/howto/order-management.md` — FT274 参照と What NOT to do セクションを追加: SKU パターン制約 (A-Z0-9-)・items 上限 50・total_cents 整数計算・cancel match 式・IDOR → 404・admin fail-closed (FT274)。 (#1108)

---

## [1.5.244] — 2026-05-27

### Added
- `docs/howto/bearer-token-middleware.md` — FT273 VULN 診断: BearerTokenMiddleware の JWT auth edge cases — alg=none BLOCKED・署名改竄 BLOCKED・期限切れ BLOCKED・nbf 未来 BLOCKED・スキーム誤り BLOCKED・部分トークン BLOCKED・IDOR (404 not 403) BLOCKED・データ分離 BLOCKED・no-exp 受付 SAFE、V-01〜V-10 全 SAFE (FT273/VULN)。 (#1106)

---

## [1.5.243] — 2026-05-27

### Added
- `docs/howto/token-lifecycle-api.md` — FT272 ATK クラッカー攻撃試験: SHA-256 ハッシュ保存（平文 DB なし）・スコープ列挙型 (read/write/admin) の CHECK 制約・IDOR ガード（actorId≠userId で 403）・revoked_at による無効化とリプレイ防止・ATK-01〜12 全 BLOCKED (FT272/ATK)。 (#1104)

---

## [1.5.242] — 2026-05-27

### Changed
- `docs/howto/notification-inbox.md` — FT271 参照と What NOT to do セクションを追加: type allowlist で未定義タイプを拒否・IDOR は 403 ではなく 404 で存在を隠す・admin fail-closed（adminKey 未設定なら false）・is_read idempotency（already_read も 200）・limit/offset を MAX_LIMIT=100 でクランプ (FT271)。 (#1102)

---

## [1.5.241] — 2026-05-27

### Changed
- `docs/howto/feature-flags.md` — FT270 参照と HTTP API 詳細を追加: 7 エンドポイント（create/get/toggle/rollout/targeting/evaluate）・FlagEvaluator の優先順位チェーン（user target → tenant target → globally_enabled → rollout_pct hash → false）・crc32 ハッシュによる決定的バケット割り当て（同一ユーザーは常に同じバケット）・abs() による符号対策・ユーザー kill switch（globally_enabled=1 でも user target enabled=false で除外）・flag name の UNIQUE 制約と 409 Conflict・target_type は 'user' か 'tenant' のみ許可 (FT270)。 (#1098)

---

## [1.5.240] — 2026-05-27

### Added
- `docs/howto/shopping-cart-api.md` — ショッピングカート API howto: UNIQUE (user_id, product_id) 制約による重複防止・upsert add-item（既存なら数量加算 SELECT-then-UPDATE/INSERT）・quantity=0 自動削除セマンティクス・JOIN による合計金額リアルタイム計算・price/subtotal を INTEGER (float 禁止)・X-User-Id ヘッダー識別（本番は JWT 推奨）・201/200 ステータス使い分け (FT269)。 (#1085)

---

## [1.5.239] — 2026-05-27

### Changed
- `docs/howto/audit-trail.md` — FT268 参照と ATK クラッカー攻撃テスト (ATK-01〜12) を追加: JWT None アルゴリズム BLOCKED・JWT 署名改竄 BLOCKED・IDOR (他ユーザーのタスク) BLOCKED・アクタ ID インジェクション BLOCKED・SQL インジェクション BLOCKED・Limit -1 DoS BLOCKED・監査ログ未認証読み取り EXPOSED (ATK-07/08)・ブルートフォース EXPOSED (ATK-11)・任意 status 値注入 EXPOSED (ATK-12)、9 BLOCKED / 4 EXPOSED (FT268/ATK)。 (#1083)

---

## [1.5.238] — 2026-05-27

### Changed
- `docs/howto/encrypted-field-storage.md` — FT267 参照と VULN アセスメント (V-01〜V-10) を追加: AES-256-GCM フィールド暗号化・12 バイトランダム nonce・16 バイト GCM 認証タグ・HMAC-SHA256 ブラインドインデックス（email 検索用）・encKey/indexKey 鍵分離・IDOR ガード (user_id スコープ)・V-01 鍵のコミット禁止 BLOCKED、V-02 nonce 再利用 BLOCKED、V-03 タグ検証 BLOCKED、V-04 復号エラー詳細漏洩 EXPOSED、V-05 ブラインドインデックス辞書攻撃 BLOCKED、V-06 認証なし EXPOSED、V-07 IDOR BLOCKED、V-08 鍵ローテーション未実装 EXPOSED、V-09 タイミング比較 BLOCKED、V-10 ログ平文漏洩 EXPOSED (FT267/VULN)。 (#1081)

---

## [1.5.237] — 2026-05-27

### Changed
- `docs/howto/api-key-management.md` — FT266 参照を追加: NENE2-FT/apikeylog との紐付け (FT266)。 (#1078)

---

## [1.5.236] — 2026-05-27

### Added
- `docs/howto/url-bookmark-api.md` — URL ブックマーク API howto: URL UNIQUE 制約 + DuplicateUrlException（UNIQUE 制約違反 → ドメイン例外 → 409 Conflict）・タグをカンマ区切り TEXT で保存・タグ LIKE マッチング 4 パターン（完全一致・先頭・中間・末尾）・カンマ区切りタグ vs M:N テーブル比較・title/url LIKE 検索・?tags=php,api クエリパース (FT265)。 (#1076)

---

## [1.5.235] — 2026-05-27

### Added
- `docs/howto/sql-injection-defence.md` — SQL インジェクション防御 howto: パラメータ化クエリ（? プレースホルダー）・LIKE ワイルドカードのパラメータ化 ('%' || ? || '%')・ORDER BY カラム許可リスト (ALLOWED_SORT_FIELDS) + 例外・sortDir ホワイトリストマッピング・ATK-01〜12 クラッカー攻撃テスト（SELECT 注入・DROP TABLE・UNION・2 次注入・積み重ねクエリ・NULL バイト BLOCKED、LIKE メタ文字フラッド EXPOSED）(FT264/ATK)。 (#1074)

---

## [1.5.234] — 2026-05-27

### Added
- `docs/howto/emoji-reactions-toggle.md` — 絵文字リアクション howto: トグルパターン（SELECT → 存在すれば DELETE・なければ INSERT）・UNIQUE(target_id, target_type, reaction_type, user_id) 複合ユニーク制約・DatabaseConstraintException 競合状態処理（remove として扱う）・GROUP BY reaction_type + COUNT(*) グループ別カウント・per-user リアクション一覧（user_id クエリパラメータ）・PUT 201（追加時） vs 200（削除時）・target_type による複数エンティティ型対応 (FT263)。 (#1072)

---

## [1.5.233] — 2026-05-27

### Added
- `docs/howto/multi-currency-money-ledger.md` — マルチカレンシー台帳 howto: Money 値オブジェクト（整数 amount_cents + ISO 4217 currency）・float 禁止・整数セントで精度保証・EntryType 文字列 BackedEnum (credit/debit)・CASE WHEN 通貨別残高集計（credit_cents / debit_cents / balance_cents を 1 クエリで計算）・CHECK(amount_cents > 0) DB レベルガード・strtoupper() 通貨正規化・amount と amount_cents 両方シリアライズ (FT262)。 (#1070)

---

## [1.5.232] — 2026-05-27

### Changed
- `docs/howto/jwt-authentication.md` — FT261/VULN 参照を追加: タイミング攻撃対策（ダミーハッシュで password_verify() を必ず実行）・VULN V-01〜10（ブルートフォース・JWT シークレット強度・トークン失効なし EXPOSED、メール大小文字正規化なし・メールバリデーションなし・HTTPS 未強制 EXPOSED、password_hash クレーム未含有 SAFE、SQL インジェクション BLOCKED）(FT261/VULN)。 (#1068)

---

## [1.5.231] — 2026-05-27

### Added
- `docs/howto/webhook-signature-verification.md` — Webhook 署名検証 howto: Stripe スタイル署名ヘッダー (X-Webhook-Signature: t=<ts>,v1=<hmac>)・タイムスタンプ+本文バインド HMAC-SHA256・hash_equals() 定数時間比較（タイミング攻撃防止）・リプレイ攻撃防止（300 秒 tolerance + abs()）・ctype_digit() タイムスタンプ検証・生ボディ先読み（JSON パース前に署名検証）・ATK-01〜12（ヘッダーなし・署名改ざん・誤シークレット・リプレイ・未来 TS・本文改ざん・ヘッダー不正 BLOCKED、空シークレット・大容量ボディ EXPOSED）(FT260/ATK)。 (#1066)

---

## [1.5.230] — 2026-05-27

### Added
- `docs/howto/leaderboard-ranking-api.md` — リーダーボード API howto: RANK() OVER ウィンドウ関数・MAX(score) プレイヤーベストスコア集計・COUNT(*) プレイ回数・バルク提出（最大 100 件・全-or-nothing バリデーション）・validateScoreEntry() 共有バリデーションヘルパー（prefix パラメータでネームスペース）・動的クエリ構築 (WHERE 1=1 + 条件追記)・ScoreNotFoundException + カスタム例外ハンドラーパターン・PaginationQueryParser + PaginationResponse (FT259)。 (#1064)

---

## [1.5.229] — 2026-05-27

### Added
- `docs/howto/bulk-operations-partial-success.md` — バルク操作・部分成功セマンティクス howto: POST /items/bulk バルク作成（各アイテム独立バリデーション・SKU 重複チェック）・DELETE /items/bulk バルク削除（not_found 追跡）・BulkResult DTO（created / errors）・HTTP 207 Multi-Status（部分成功時）vs 201 Created（全成功時）・インデックス付きエラー（index フィールドで失敗アイテム特定）・all-or-nothing vs 部分成功 vs 非同期キュー比較 (FT258)。 (#1062)

---

## [1.5.228] — 2026-05-27

### Added
- `docs/howto/soft-delete-trash-purge.md` — ソフトデリート / ゴミ箱 / 永久削除 howto: deleted_at タイムスタンプによるソフトデリート・アクティブリスト (WHERE deleted_at IS NULL) vs ゴミ箱リスト・findById(includeTrashed: bool) 二段フラグパターン・restore（deleted_at = NULL リセット）・purge（物理 DELETE、ゴミ箱のみ対象）・ルート衝突回避 (/notes/trash を {id} より先に登録)・HTTP 動詞選択根拠 (DELETE=ソフトデリート/パージ、POST=復元) (FT257)。 (#1060)

---

## [1.5.227] — 2026-05-27

### Added
- `docs/howto/mass-assignment-defence.md` — マスアサインメント防御 howto: CreateUserInput readonly DTO によるフィールドホワイトリスト（name/email のみ）・role/is_active/created_at/id はサーバー側でハードコード・コントローラーによる明示的フィールド抽出・許可リスト vs ブロックリスト比較・ATK-01〜12 クラッカー攻撃テスト（ロール昇格・アカウント状態操作・タイムスタンプ改ざん・ID ハイジャック・SQL インジェクション・メールケースバイパス BLOCKED、重複メール未ハンドリング・長さ制限なし EXPOSED、XSS ACCEPTED BY DESIGN）(FT256/ATK)。 (#1058)

---

## [1.5.226] — 2026-05-27

### Added
- `docs/howto/job-queue-with-retry.md` — バックグラウンドジョブキュー howto: JobPriority 数値 BackedEnum・クレームパターン（非原子 SELECT+UPDATE）・retry_count < max_retries → 再キュー vs 失敗・冪等性キー重複防止・VULN V-01〜10（認証なし・型未検証・優先度操作・ワーカーID 詐称・所有権チェックなし・競合状態・ペイロードサイズ・SQL インジェクション BLOCKED・冪等性キー衝突 PARTIALLY・エラーメッセージ漏洩）(FT255/VULN)。 (#1056)

---

## [1.5.225] — 2026-05-27

### Added
- `docs/howto/sqlite-fts5-search.md` — SQLite FTS5 全文検索 howto: content='posts' 外部コンテンツテーブル・INSERT/DELETE/UPDATE トリガー自動同期・WHERE posts_fts MATCH ? 検索・fts.rank 関連度スコア順・タグスペース区切り文字列・無効クエリ try-catch → 400・FTS5 クエリ構文（フレーズ・プレフィックス・カラム限定）・LIKE との比較表 (FT254)。 (#1054)

---

## [1.5.224] — 2026-05-27

### Added
- `docs/howto/transaction-scope-pattern.md` — DB トランザクションスコープパターン howto: transactional() コールバック内でのリポジトリ生成（executor scope trap 解説）・在庫減算 + 注文作成の原子的 2 書き込み・InsufficientStockException → ロールバック → 422・複数アイテム途中失敗の全ロールバック保証・CHECK(stock >= 0) セーフガード (FT253)。 (#1052)

---

## [1.5.223] — 2026-05-27

### Changed
- `docs/howto/pin-verification-lockout.md` — FT252/ATK 参照を追加: NENE2-FT/pinverifylog との紐付け・HMAC-SHA256 PIN ハッシュ・hash_equals() 定数時間比較・ブルートフォースロックアウト・fail-closed 管理キー・ctype_digit() ReDoS 防止・ATK-01〜12 全 BLOCKED 対策済み。 (#1050)

---

## [1.5.222] — 2026-05-27

### Added
- `docs/howto/fixed-window-rate-limiter.md` — 固定ウィンドウレートリミッター howto: SQLite upsert（INSERT ON CONFLICT DO UPDATE SET count = count + 1）・ウィンドウ境界切り捨て（ts - ts%window）・429 Too Many Requests + Retry-After ヘッダー・X-Client-Key ヘッダーキー・read-only status エンドポイント・pruneExpired 掃除パターン (FT251)。 (#1048)

---

## [1.5.221] — 2026-05-27

### Added
- `docs/howto/multi-value-tag-filter.md` — マルチバリュータグフィルター API howto: M:N join table（post_tags・PRIMARY KEY(post_id, tag)）・AND 検索（HAVING COUNT(DISTINCT tag) = CAST(? AS INTEGER)）・OR 検索（SELECT DISTINCT）・カンマ区切り/PHP 配列スタイル二重クエリ形式・INSERT OR IGNORE タグ重複防止 (FT250)。 (#1046)

---

## [1.5.220] — 2026-05-27

### Added
- `docs/howto/article-versioning-api.md` — 記事バージョン管理 API howto: current_version INTEGER カラムパターン・read-then-increment 非原子更新・非破壊ロールバック・バージョン一覧 body 省略・UNIQUE(article_id, version)、VULN V-01〜10 (無認証・IDOR・レース条件・非トランザクション・バージョン列挙・body サイズ) (FT249/VULN)。 (#1044)

---

## [1.5.219] — 2026-05-27

### Added
- `docs/howto/content-approval-workflow.md` — コンテンツ承認ワークフロー API howto: PostStatus BackedEnum canTransitionTo()・InvalidTransitionException → 409・tryFrom() ステータスフィルタ・optional reject_reason・terminal 状態防御、ATK-01〜12 (無認証・不正遷移・author詐称・mass assignment など) (FT248/ATK)。 (#1042)

---

## [1.5.218] — 2026-05-27

### Added
- `docs/howto/step-workflow-approval.md` — ステップワークフロー承認 API howto: ワークフロー定義 + step_order 自動採番・workflow_runs 状態機械 (in_progress → completed/rejected)・findNextStep (step_order > current LIMIT 1)・recordAction 履歴 JOIN・LEFT JOIN で current_step_name 取得・409 (ステップなし / 非 in_progress) (FT247)。 (#1040)

---

## [1.5.217] — 2026-05-27

### Added
- `docs/howto/time-tracking.md` — タイムトラッキング API howto: NULL end_time = running パターン・シングルトンタイマー (TimerAlreadyRunning / NoRunningTimer → 409)・julianday() による秒計算・date() 関数フィルタ・LIKE 部分一致検索・dailySummary GROUP BY (FT246)。 (#1038)

---

## [1.5.216] — 2026-05-27

### Added
- `docs/howto/aggregate-reporting.md` — 多次元集計レポート API howto: COALESCE(SUM/AVG)・COUNT(CASE WHEN) 条件付きカウント・substr() 日付切り出し GROUP BY・byStatus / topItems 集計・dateFilter() 動的 WHERE・createFromFormat() ラウンドトリップ日付検証 (FT245)。 (#1036)

---

## [1.5.215] — 2026-05-27

### Added
- `docs/howto/budget-tracking.md` — 家計簿 API howto: income/expense/transfer トランザクション型・TransferFundsUseCase (残高チェック + DB トランザクション)・QueryStringParser マルチフィルタ (category/min_amount/max_amount/recurring)・sumByCategory 集計、ATK-01〜12 (無認証・残高マイナス・レース条件・float切り捨て・bool強制など) (FT244/ATK)。 (#1034)

---

## [1.5.214] — 2026-05-27

### Added
- `docs/howto/event-analytics-api.md` — イベント収集・集計 API howto: JSON properties blob・json_extract() 検索・strftime GROUP BY 集計・静的ルート優先登録、VULN V-01〜V-10 (無認証・user_id詐称・occurred_at改ざん・JSON path連結・event_type長・properties サイズ・レート制限なし) (FT243/VULN)。 (#1032)

---

## [1.5.213] — 2026-05-27

### Added
- `docs/howto/cursor-pagination.md` — カーソルベースページネーション howto: ID ベースカーソル (`WHERE id < ? ORDER BY id DESC`)・limit+1 による has_more 判定・next_cursor レスポンスフィールド・ctype_digit() カーソル検証・limit クランプ・オフセットページネーションとの比較 (FT242)。 (#1030)

---

## [1.5.212] — 2026-05-27

### Added
- `docs/howto/project-task-management.md` — プロジェクト・タスク管理 API ガイド: ネストリソース設計 (`/projects/{projectId}/tasks/{taskId}`)・親存在検証 (ProjectNotFoundException → 404)・array_key_exists() による PATCH 選択更新・is_int() priority 厳密検証・status 許可リスト・QueryStringParser ステータスフィルタ・204 No Content DELETE (FT241)。 (#1028)

---

## [1.5.211] — 2026-05-27

### Added
- `docs/howto/note-management-ownership.md` — ノート管理 API ガイド: X-Auth-User ヘッダー認証・findByIdAndOwner() IDOR 防止・フィールドマージ更新・ATK-01〜12 攻撃テスト (FT240)。 (#1026)

---

## [1.5.210] — 2026-05-27

### Added
- `docs/howto/document-versioning.md` — ドキュメントバージョニング API ガイド: is_current フラグ・非破壊リバート (コピーとして新バージョン追加)・DatabaseTransactionManagerInterface::transactional()・PaginationQueryParser/Response・ValidationException (FT239)。 (#1024)

---

## [1.5.209] — 2026-05-27

### Added
- `docs/howto/contact-management.md` — コンタクト管理 API ガイド: contact_groups 多対多・LIKE + EXISTS サブクエリ動的検索・owner_id IDOR 防止・DatabaseConstraintException による重複グループ 409・冪等 addToGroup (FT238)。 (#1022)

---

## [1.5.208] — 2026-05-27

### Added
- `docs/howto/state-machine-audit-log.md` — 状態遷移ログ API ガイド: 遷移監査テーブル・InvalidTransitionException → 409 + from/to コンテキスト・2-write パターン・VULN V-01〜V-10 脆弱性評価 (FT237)。 (#1020)

---

## [1.5.207] — 2026-05-27

### Added
- `docs/howto/quota-management.md` — クォータ管理 API ガイド: QuotaWindow enum windowStart()・check/consume 分離・429 Too Many Requests・usage = usage + 1 原子インクリメント・ATK-01〜12 攻撃テスト (FT236)。 (#1018)

---

## [1.5.206] — 2026-05-27

### Added
- `docs/howto/scheduled-reminders.md` — スケジュールリマインダー API ガイド: V::futureDatetime() タイムゾーン対応未来日時バリデーション・X-User-Id ヘッダー認証・findForUser() IDOR 防止・fetch-first 404/409 区別 (FT235)。 (#1016)

---

## [1.5.205] — 2026-05-27

### Added
- `docs/howto/credit-ledger.md` — クレジット台帳 API ガイド: direction ±1 台帳モデル・COALESCE(SUM(amount*direction)) 残高計算・InsufficientCreditsException → 409・冪等性キー (UNIQUE + DatabaseConstraintException) パターン (FT234)。 (#1014)

---

## [1.5.204] — 2026-05-27

### Added
- `docs/howto/cqrs-pattern.md` — CQRS パターン API ガイド: Command/Query 分離・WriteModel/ReadModel ハンドラー・SQL VIEW による非正規化 read model・「command then query」レスポンスパターン (FT233)。 (#1012)

---

## [1.5.203] — 2026-05-27

### Added
- `docs/howto/multilingual-content.md` — 多言語コンテンツ API ガイド: BCP 47 ロケール検証・翻訳 upsert・ロケールフォールバック・公開状態管理・ATK-01〜12 攻撃テスト (FT232)。 (#1010)

---

## [1.5.202] — 2026-05-27

### Added
- `docs/howto/bulk-status-update.md` — 一括ステータス更新 API ガイド: 部分成功・IN 句パラメータ化・backed enum バリデーション・VULN V-01〜V-10 脆弱性評価 (FT231)。 (#1008)

---

## [1.5.201] — 2026-05-27

### Added
- `docs/howto/dead-letter-queue.md` — デッドレターキュー API ガイド: クレイム・指数バックオフリトライ (2^n 秒、最大 3600 秒)・DLQ・リプレイ・複数キュー (FT230)。 (#1006)

---

## [1.5.200] — 2026-05-27

### Added
- `docs/howto/approval-workflow.md` — 承認ワークフロー API ガイド: backed enum による状態遷移ルール・canTransitionTo() パターン・reject 必須理由・rework でのレビュー状態クリア (FT229)。 (#1004)

---

## [1.5.199] — 2026-05-27

### Added
- `docs/howto/price-history.md` — 価格履歴 API ガイド: 価格ティア・時点クエリ・cents 整数バリデーション・ATK-01〜12 クラッカー攻撃テスト (FT228)。 (#1002)

---

## [1.5.198] — 2026-05-27

### Added
- `docs/howto/media-watchlist.md` — メディア視聴リスト API ガイド: backed enum バリデーション・array_key_exists 利用の null 許容フィールド・archive/restore POST アクションパターン・評価 1-5 整数チェック (FT227)。 (#1000)

---

## [1.5.197] — 2026-05-27

### Added
- `docs/howto/event-analytics.md` — イベント分析 API ガイド: json_extract JSON プロパティフィルタ・strftime 日別集計・COUNT(DISTINCT) ユニークユーザー・静的ルート優先登録パターン (FT226)。 (#998)

---

## [1.5.196] — 2026-05-27

### Added
- `docs/howto/shift-management.md` — シフト管理 API ガイド: オーバーラップ検出・トランザクション・ISO 8601 比較・VULN V-01〜V-12 脆弱性評価 (FT225)。 (#996)

---

## [1.5.195] — 2026-05-27

### Added
- `docs/howto/habit-tracker.md` — 習慣トラッカー API ガイド: 頻度 allowlist・重複完了 409 Conflict・ストリーク計算・ATK-01〜12 クラッカー攻撃テスト (FT224)。 (#994)

---

## [1.5.194] — 2026-05-27

### Added
- `docs/howto/expense-tracker.md` — 経費追跡 API ガイド: カテゴリ allowlist・amount is_int 検証・ISO 8601 日付 roundtrip・月次サマリー GROUP BY・PATCH 部分更新 (FT223)。 (#992)

---

## [1.5.193] — 2026-05-27

### Added
- Batch howto docs — FT181〜FT214 全 32 ガイドを一括追加:
  `iso-datetime-validation`, `batch-api-partial-success`, `url-shortener-ssrf`, `one-time-secrets`,
  `service-status-page`, `session-management`, `encrypted-field-storage`, `numeric-verification-code`,
  `privacy-consent-management`, `system-announcement-management`, `waitlist-management`,
  `pin-verification-lockout`, `asset-checkout`, `secret-vault`, `event-ticket-booking`,
  `document-template-engine`, `multi-currency-wallet`, `live-poll-system`,
  `sliding-window-rate-limiter`, `resource-booking`, `note-management-with-tags`,
  `inventory-stock-management`, `feedback-collection`, `wish-list-api`,
  `session-token-management`, `tag-label-api`, `file-upload-metadata`, `comment-thread`,
  `product-catalog`, `subscription-plan`, `notification-queue`

---

## [1.5.161] — 2026-05-27

### Added
- `docs/howto/sql-orderby-injection.md` — SQL ORDER BY インジェクション防止ガイド: allowlist + in_array strict・VULN-A〜L + ATK-01〜12 全 Pass (FT180)。 (#903)

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
