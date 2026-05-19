# Field Trial 12-A — tagmark: 多対多リレーション実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.x（`hideyukimori/nene2: ^1.4`）
- PHP 8.4（Docker: `php:8.4-cli` ベースイメージ）
- プロジェクト: **tagmark** — ブックマーク管理 JSON API
- エンティティ: `User` / `Bookmark` / `Tag`（M:N）
- テスト: PHPUnit・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

多対多リレーション（Bookmark ↔ Tag）を持つドメインが、NENE2 のドキュメントだけを参照した Claude が迷わず実装できるかを検証する。

---

## Steps Taken

1. CLAUDE.md・`add-jwt-authentication.md`・`add-database-endpoint.md`・`add-second-entity.md` を読み込んだ。
2. `vendor/hideyukimori/nene2/src/` を参照してメソッドシグネチャを確認した。
3. Auth ドメイン（User・UserRepository・RegisterUseCase・LoginUseCase・AuthRouteRegistrar）を実装した。
4. Tag ドメインと Bookmark ドメインを実装した。JOIN クエリ・CASCADE 削除・tag フィルタリングを含む。
5. `BearerTokenMiddleware` が `excludedPaths`（除外リスト）ではなく `protectedPaths`（許可リスト）であることを発見し、`ExcludedPathsBearerMiddleware` ラッパーを実装した。
6. `RuntimeApplicationFactory` の型制約により手動パイプライン構築。
7. `database/schema.sql` を作成し、フロントコントローラー内で自動初期化するパターンを採用。
8. PHPUnit テスト（19件）を作成し、全エンドポイント・M:N 操作・カスケード削除・所有権チェックを網羅。
9. `composer check` 全通過を確認。

---

## Findings

### F-1: `BearerTokenMiddleware` の `excludedPaths` パラメータが実在しない [高]

`add-jwt-authentication.md` は `BearerTokenMiddleware` のパラメータ名を `excludedPaths`（除外リスト）と記述しているが、v1.4 の実装は `protectedPaths`（許可リスト）。ドキュメント通りに実装するとコンストラクタエラーになる。

**解決**: `ExcludedPathsBearerMiddleware`（PSR-15 ラッパー）を自前実装。`RuntimeApplicationFactory` の型制約により、これを渡せないためパイプラインを手動構築した。

**提案**:
1. `BearerTokenMiddleware` に `excludedPaths` パラメータを追加する（またはドキュメントを実装に合わせて修正）。
2. `RuntimeApplicationFactory` の `$bearerTokenMiddleware` パラメータの型を `?MiddlewareInterface` に緩和する。

---

### F-2: `add-jwt-authentication.md` の `TokenIssuerInterface` が v1.4 に存在しない [高]

`RegisterUseCase` / `LoginUseCase` に JWT 発行機能を注入するため `Nene2\Auth\TokenIssuerInterface` を `use` したが、v1.4 の `vendor/` に存在しない。

**解決**: `Tagmark\Auth\TokenIssuerInterface` を自前定義し、`LocalBearerTokenVerifier::issue()` へのブリッジを実装。

**提案**: `Nene2\Auth\TokenIssuerInterface` を v1.4 に追加し、`LocalBearerTokenVerifier` が実装するようにする。ADR 0009 の公開 API 一覧にも追記する。

---

### F-3: `DomainExceptionHandlerInterface::handles()` が実際は `supports()` [中]

`add-jwt-authentication.md` は `handles(Throwable $e): bool` と記述しているが、v1.4 の実装は `supports(Throwable $exception): bool`。

**解決**: `vendor/` を確認して正しいメソッド名を使用。

**提案**: howto のサンプルコードを修正する。

---

### F-4: ハンドラーが配列を返せるという記述が不正確 [中]

`add-database-endpoint.md` は「ハンドラーは array を返せる」と説明しているが、v1.4 の Router は `callable(ServerRequestInterface): ResponseInterface` を要求する。

**解決**: `JsonResponseFactory::create()` を使って `ResponseInterface` を返すパターンに従った。

---

### F-5: `getParsedBody()` で JSON ボディが読めない [中]

howto のサンプルが `$request->getParsedBody()` を使っているが、JSON リクエストでは `null` を返す。`JsonRequestBodyParser::parse()` が必要。

**解決**: `vendor/` 内のサンプルコードを参照して修正。

---

### F-6: M:N カスケード削除はアプリ側で手動管理が必要 [低]

SQLite は `PRAGMA foreign_keys = ON` なしでは外部キー制約が機能しないため、中間テーブル行を手動削除する必要がある。

**提案**: M:N パターンの howto に SQLite カスケード削除のパターンを追記する。

---

### F-7: `RuntimeApplicationFactory` に任意の認証ミドルウェアを渡せない [中]

`$bearerTokenMiddleware` の型が `?BearerTokenMiddleware` に固定されているため、F-1 で作成した `ExcludedPathsBearerMiddleware` を注入できなかった。

**提案**: F-1 と同じ — 型を `?MiddlewareInterface` に変更するか、`excludedPaths` オプションを追加する。

---

## Test Results

```
PHPUnit:         19/19 tests, 45 assertions
PHPStan level 8: OK
PHP-CS-Fixer:    0 files to fix
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `BearerTokenMiddleware` が `excludedPaths` ではなく `protectedPaths`（ドキュメント誤り） | 高 | ドキュメント誤り + API 設計 |
| F-2 | `TokenIssuerInterface` が v1.4 に存在しない | 高 | API 欠損 |
| F-3 | `DomainExceptionHandlerInterface::handles()` が正しくは `supports()` | 中 | ドキュメント誤り |
| F-4 | ハンドラーが配列を返せると書かれているが実際は `ResponseInterface` 必須 | 中 | ドキュメント誤り |
| F-5 | `getParsedBody()` で JSON が読めない（`JsonRequestBodyParser` 必須） | 中 | ドキュメント誤り |
| F-6 | M:N カスケード削除パターンのドキュメントなし | 低 | ドキュメント欠損 |
| F-7 | `RuntimeApplicationFactory` に任意の認証ミドルウェアを渡せない | 中 | API 設計 |

---

## Overall Impression

NENE2 のコアアーキテクチャは直感的で、M:N 実装自体は迷わず書けた。最大の障害は**ドキュメントと v1.4 実装の乖離**（F-1〜F-5）で、`vendor/` を直接読まなければ実装を完成できなかった。`composer check` が PHPStan level 8 まで全通過した点は品質保証として有効に機能した。ドキュメントを v1.4 に追いつかせることで次の Field Trial は大幅に改善するはずである。
