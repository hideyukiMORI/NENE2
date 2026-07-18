# CLAUDE.md — NENE2

Claude Code がこのリポジトリで作業するための中核ハンドブック。
**常に守る中核ルール**と **NENE2 固有の要注意点**だけをここに置き、詳細は `docs/` の正本を参照する。

- AI エージェント入口（英語・簡潔版）: `AGENTS.md`
- 詳細ポリシーの正本は下の「詳細ドキュメント索引」を参照（このファイルには複製しない）。

---

## プロジェクト哲学

NENE2 は PHP 8.4 製のミニマル API フレームワーク。

- **API first**: JSON API と OpenAPI 契約を中心に据える。
- **薄い HTML**: サーバー HTML はオプション。SPA シェルで差し替えやすく保つ。
- **AI-readable**: 明示的ディレクトリ、小さなクラス、型付き境界、記録された決定。
- **LLM delivery ready**: API・MCP・認証・DB・引き継ぎドキュメントを整合させる。
- **モダン PHP**: strict_types、PSR 標準、DI、自動テスト、静的解析。

Non-goals: Laravel/Symfony 再発明、マジック規約で動作を隠す、フロントエンドライブラリへの依存、直接 DB への AI アクセス。

---

## 中核ルール（常に適用）

### Issue ドリブンワークフロー

- コード・ドキュメント・設定変更は **必ず GitHub Issue ベース**で進める。Issue が無ければ先に作る。
- **`main` へ直接コミットしない**。ブランチ名は `type/issue-number-summary`（例 `feat/12-openapi-router`）。
- 標準フロー: Issue → ブランチ → 最小変更 → ドキュメント更新 → セルフレビュー → 最狭の検証 → Conventional Commits → push → PR → CI 通過後 merge → ローカル `main` を同期。
- 変更は依頼範囲と Issue の目的に留め、無関係な整形・リファクタ・別種の作業を 1 PR に混ぜない。
- **ユーザーが「調査のみ」「コミットしない」「PR まで」等スコープを明示したらそれを優先**する。
- PR 本文には 目的 / 変更点 / 検証結果 / `Closes #番号` / 使用したセルフレビュー名 / 残リスクを書く。
- 詳細: `docs/workflow.md` / `docs/CONTRIBUTING.md`

### コミットメッセージ規約

```
<type>(<scope>): <日本語の説明> (#<issue番号>)

[オプション本文 — 変更理由、トレードオフ、後続作業]
```

- `type` / `scope` / Conventional Commits キーワードは **英語**、description と body は **日本語**。
- `type`: `feat` / `fix` / `docs` / `refactor` / `test` / `build` / `ci` / `chore`。
- 公開 API 変更は `!` または `BREAKING CHANGE:` フッターを付ける。
- 詳細: `docs/development/commit-conventions.md`

### CHANGELOG 記述規約

`CHANGELOG.md` は**公開・汎用フレームワークの利用者向け**文書。Keep a Changelog の精神で「利用者に影響する変更（what）」だけを書く。内部文脈（why の詳細・経緯）は書かず、必要なら内部ドキュメント（`_work/` 側・handoff）に置く。

- **書く**: Added / Changed / Deprecated / Removed / Fixed / Security の観点で、利用者から見た振る舞いの差分。公開 API 変更・移行手順。
- **書かない**（NENE2 はほぼ常に公開リポ。「検索に載って困る文脈は書かない」が原則）:
  - 本番障害の内幕・再現経緯（どの製品で何が漏れた等の内部インシデント詳細）
  - 施主・意思決定の経緯（誰がいつ何を裁定したか）
  - `_work/` パス・handoff ファイル名
  - 他リポ（NeNe 製品）の issue/PR 番号・フリート内部語彙（リナ / council / W1 / 批准 等）
  - 未公開のローンチ日程・未リリース計画
  - インフラ固有名（ホスティング名・FQDN・IP・サーバパス）
- 内部文脈が要る変更は **what だけ書き、why は内部 docs へ**回す（CHANGELOG からは参照しない）。
- 既存 CHANGELOG の遡及是正は別レーン。この規約は今後の記述に適用する。

### 安全ルール

- **シークレット禁止**: パスワード・トークン・ローカル `.env`・生成物をコミットしない。
- 破壊的操作（`git reset --hard`、force push、DB 削除等）は**ユーザーの明示的な承認**を得てから行う。
- MCP・AI ツールは DB 直結せず、ドキュメントされた API 境界を通す。プロダクション認証情報にアクセスしない。

### 言語ポリシー

- **英語で書く**: `README.md` / `docs/development/` / `docs/integrations/` / `docs/adr/` / `docs/review/` / OpenAPI / Problem Details `title`・`type` / validation `code` / コード識別子 / パッケージメタデータ。
- **日本語可**: `.cursor/rules/` / GitHub Issue・PR ボディ / コミット description・body / ローカル TODO・引き継ぎノート。
- 日本語ノートが英語ポリシー文書と矛盾したら **英語文書を先に更新**する。
- 詳細: `docs/development/language-policy.md`

---

## NENE2 固有の要注意点（間違えやすい箇所）

### ローカルポートレジストリ

| アプリ | HTTP | MySQL |
|---|---|---|
| **NENE2**（このリポジトリ） | **8200** | **3316** |
| NENE2-FT（フィールドトライアル MySQL） | — | 3308 |
| NeNe Clear | 83xx | — |
| NeNe Records | 180xx | — |

新規プロジェクトは `82xx` / `83xx` / `180xx` レンジ外から選ぶ。`compose.yaml` を変えたらこの表も更新する。

### 環境変数の罠

- **DB パスワードは `DB_PASSWORD`**（`DB_PASS` ではない — `ConfigLoader` が読むキー）。
- `.env` の raw アクセスは `ConfigLoader` 内のみ。アプリコードは型付き config（`AppConfig` / `DatabaseConfig`）を受け取る。
- 主なキー: `APP_ENV` / `DB_ADAPTER`（sqlite/mysql/pgsql）/ `NENE2_MACHINE_API_KEY` / `NENE2_LOCAL_JWT_SECRET` / `PROBLEM_DETAILS_BASE_URL`。全一覧は `docs/development/configuration.md`。

### ミドルウェア順序（セキュリティ上の意図あり・崩さない）

```
1. Request id  2. Request logging  3. Security headers  4. CORS  5. Error handling
6. Request size limit  7. Auth/authorization  8. Rate limiting (optional)  9. Routing/dispatch
```

RequestId・Security headers・CORS を Error handling より**外側**に置くことで、エラーレスポンスにも `X-Request-Id` / セキュリティヘッダー / `Access-Control-Allow-Origin` が付く。Rate limiting は Auth の後ろ（認証済みユーザー単位の制限）。ログにシークレット・トークン・Cookie・`Authorization`・生ボディを出さない。詳細: `docs/development/middleware-security.md` / `observability.md`。

### エラー・バリデーション・MCP・FT 参照

- ユーザー向け JSON エラーは **RFC 9457 Problem Details**（`application/problem+json`）。スタックトレース・SQL・パス・シークレットを漏らさない。`type` は `https://nene2.dev/problems/{name}`。
- バリデーションは layered（Middleware / Controller / UseCase）。失敗は `validation-failed`（HTTP 422）。詳細: `docs/development/request-validation.md`。
- **MCP は read-only ツールから**。`write`/`admin`/`destructive` は認証・認可・監査・request id・確認動作が必須。カタログ `docs/mcp/tools.json`（`composer mcp` で検証）。
- **FT/consumer から参照するとき**: 実際のシグネチャは `vendor/hideyukimori/nene2/` を優先（`../NENE2/src/` は未リリース HEAD を含む）。PHPStan level 8 で乖離を早期検出。
- **howto を触ったら** `composer howto:index` を再生成してコミット＋ frontmatter 必須（付け忘れ／再生成漏れで CI が落ちる。#1331）。

---

## よく使うコマンド

```bash
# 標準チェック（test + analyse + cs + openapi 等が一括で走る）
docker compose run --rm app composer check

# 個別
docker compose run --rm app composer test | analyse | cs | cs:fix | openapi | mcp
docker compose run --rm app composer howto:index                        # howto 索引再生成
docker compose run --rm app composer howto:frontmatter -- --require-all  # frontmatter 検証（CI と同条件）
npm run check --prefix frontend                                         # type-check + lint + format

# ローカル HTTP スモーク
docker compose up -d app
curl -i http://localhost:8200/health
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8200/machine/health
```

DB・マイグレーション・MCP サーバー起動・エンドポイント追加手順は下の索引を参照。

---

## セルフレビュー（PR 前に必須）

作業タイプに合うチェックリストを `docs/review/` から使い、PR 本文に名前を書く（例 `Self-review: backend-api, middleware-security`）:
`backend-api` / `openapi-contract` / `middleware-security` / `database` / `frontend` / `view-rendering` / `release-ci` / `docs-policy`。

---

## 詳細ドキュメント索引（正本）

| 目的 | ドキュメント |
|---|---|
| プロジェクト概要 | `README.md` |
| コラボレーション方針 / ワークフロー | `docs/CONTRIBUTING.md` / `docs/workflow.md` |
| 現在のタスク・引き継ぎ | `docs/todo/current.md` |
| ロードマップ | `docs/roadmap.md` |
| PHP コーディング規約 | `docs/development/coding-standards.md` |
| HTTP ランタイム / DI / ドメイン層 | `docs/development/http-runtime.md` / `dependency-injection.md` / `domain-layer.md` |
| リクエストバリデーション | `docs/development/request-validation.md` |
| ミドルウェア・セキュリティ / オブザーバビリティ | `docs/development/middleware-security.md` / `observability.md` |
| API エラーレスポンス | `docs/development/api-error-responses.md` |
| エンドポイント追加手順 | `docs/development/endpoint-scaffold.md` |
| 設定・環境変数 | `docs/development/configuration.md` |
| DB マイグレーション / テスト戦略 | `docs/development/database-migrations.md` / `test-database-strategy.md` |
| フロントエンド統合 | `docs/development/frontend-integration.md` |
| プロジェクトレイアウト | `docs/development/project-layout.md` |
| Docker / セットアップ / 品質ツール | `docs/development/docker.md` / `setup.md` / `quality-tools.md` |
| ADR ポリシー | `docs/development/adr.md`（ファイル: `docs/adr/NNNN-*.md`） |
| リリース・バージョニング | `docs/development/release-checklist.md` / `release-ci.md` |
| コミット規約 / 言語ポリシー | `docs/development/commit-conventions.md` / `language-policy.md` |
| ドキュメントコメント | `docs/development/documentation-comments.md` |
| AI ツールポリシー | `docs/integrations/ai-tools.md` |
| MCP ツール定義 | `docs/mcp/tools.json` |
| **FT ループ / ATK・VULN / DX Trial 機構** | `docs/todo/ft-loop-workflow.md` |

> 現在の作業レーンと未処理 Issue は必ず `docs/todo/current.md` を先に読むこと。
> このハンドブックと詳細ドキュメントが矛盾した場合は、詳細ドキュメント（正本）を先に直す。
