# Field Trial 12-B — knowledgelog: MCP Ergonomics 実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.1（`hideyukimori/nene2: ^1.4`）
- PHP 8.4
- プロジェクト: **knowledgelog** — ナレッジベース JSON API
- エンティティ: `Article` / `Category`（2ドメイン）
- テスト: PHPUnit・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

MCP ツールを主インターフェースとして設計した場合の摩擦を記録する。
公開 read エンドポイントと管理者 write エンドポイントを実装し、`docs/mcp/tools.json` を定義して `composer check` と MCP バリデーションを通過させる。

---

## Steps Taken

1. `docker compose run --rm app composer install` で依存解決。
2. `vendor/hideyukimori/nene2/src/` を読んで実際のクラス・メソッドシグネチャを確認。
3. `tasklog` (FT11) の `AppServiceProvider` パターンを参照してアプリケーション骨格を構築。
4. SQLite スキーマ（`database/schema.sql`）とフロントコントローラーを実装。
5. Article / Category ドメイン、Repository、RouteRegistrar を実装。
6. 書き込み保護のため `WriteApiKeyMiddleware` を自作（F-1 参照）。
7. PHPUnit / PHPStan / CS-Fixer 全通過。
8. `docs/openapi/openapi.yaml` と `docs/mcp/tools.json`（7ツール）を作成。
9. MCP バリデーター制約を調査し `tools/validate-mcp.php` ラッパーを実装（F-2 参照）。
10. `symfony/yaml` を `require-dev` に追加（F-3 参照）。

---

## Findings

### F-1: `ApiKeyAuthenticationMiddleware` が動的パスと HTTP メソッドの組み合わせに非対応 [中]

`$protectedPaths` は exact-match のため、`/articles/{id}` のような動的パスや GET/POST の分岐に対応できない。

**解決**: HTTP メソッド（POST/PUT/DELETE/PATCH）単位で API キー検証を行う `WriteApiKeyMiddleware` を自作。

**提案**: `ApiKeyAuthenticationMiddleware` に `$protectedMethods` オプションを追加する。

---

### F-2: `vendor/hideyukimori/nene2/tools/validate-mcp-tools.php` が Consumer Project から動かない [高]

スクリプト内の `$root = dirname(__DIR__)` が `vendor/hideyukimori/nene2/` を指すため、Consumer Project の `docs/mcp/tools.json` ではなく NENE2 パッケージ自身の docs を読もうとする。

**解決**: `tools/validate-mcp.php` という同等ロジックのラッパーを Consumer Project に作成し、`$root` を Consumer Project ルートに向けた。

**提案**: `validate-mcp-tools.php` に `--root=<path>` オプションを追加する（または Composer bin スクリプトとして公開する）。

---

### F-3: `symfony/yaml` が Consumer Project の `vendor` に存在しない [高]

`validate-mcp-tools.php` は `symfony/yaml` を使っているが、NENE2 の `composer.json` では `require-dev` に分類されている。Consumer Project の `composer install` では dev 依存が含まれないため、クラスが見つからないエラーが発生した。

**解決**: Consumer Project の `require-dev` に `symfony/yaml` を追加してインストール。

**提案**: `require` へ移動するか、`symfony/yaml` を使わない実装に置き換える。または MCP バリデーターを独立した Composer パッケージとして公開する。

---

### F-4: `nene2.auth.*` リクエスト属性名がドキュメントにない [低]

`ApiKeyAuthenticationMiddleware::process()` が付与する `nene2.auth.credential_type` 属性名がドキュメントに記載されていない。

**提案**: ADR や PHPDoc に `nene2.auth.*` 属性名を記載するか、`RequestAttribute` 定数クラスとして公開する。

---

### F-5: `RuntimeApplicationFactory` が Note/Tag の ServiceProvider と密結合 [中]

`RuntimeServiceProvider` が `NoteServiceProvider`・`TagServiceProvider` を必ず `addProvider()` する。Consumer Project は Note/Tag に依存したくないため `RuntimeApplicationFactory` を使用できなかった。

**解決**: Consumer Project 専用の `AppServiceProvider`・`AppContainerFactory` を自作し、`MiddlewareDispatcher` + `Router` を直接組み立てた（FT11 と同じパターン）。

**提案**: `RuntimeApplicationFactory` を Consumer Project 向けに拡張可能にする、または Example の Note/Tag をデフォルトで登録しないオプションを提供する。

---

## Test Results

```
PHPUnit:         10/10 tests, 30 assertions
PHPStan level 8: No errors
PHP-CS-Fixer:    0 files to fix
MCP validation:  MCP tool catalog is valid.
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `ApiKeyAuthenticationMiddleware` が動的パスを保護できない | 中 | API 設計 |
| F-2 | `validate-mcp-tools.php` が Consumer Project から動かない | 高 | ツール設計 |
| F-3 | `symfony/yaml` が Consumer の `vendor` に存在しない | 高 | 依存管理 |
| F-4 | `nene2.auth.*` 属性名がドキュメントにない | 低 | ドキュメント |
| F-5 | `RuntimeApplicationFactory` が Note/Tag に密結合 | 中 | アーキテクチャ |

---

## Overall Impression

NENE2 v1.4.1 のコア API は Consumer Project から非常に使いやすい。一方、**MCP ツール周辺のエルゴノミクスには改善余地がある**。`validate-mcp-tools.php` が Consumer Project から「そのまま使えない」のは MCP をフォーカスした今回の最大の摩擦だった。ラッパースクリプトで回避できるが、ドキュメントがなければ詰まるポイントであり、Next Release で優先的に解決すべき課題。
