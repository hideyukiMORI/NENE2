# Field Trial 11 — tasklog: JWT 認証フロー実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.0（`hideyukimori/nene2: ^1.4`）
- PHP 8.4（Docker: `php:8.4-cli` ベースイメージ）
- プロジェクト: **tasklog** — タスク管理 JSON API
- エンティティ: `User` / `Task`（2ドメイン、7エンドポイント）
- テスト: PHPUnit・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

JWT Bearer 認証フロー（ユーザー登録・ログイン・認証保護エンドポイント）が、
NENE2 のドキュメントだけを参照した Claude が迷わず実装できるかを検証する。

---

## Steps Taken

1. `docs/adr/0008-jwt-authentication.md`、`src/Auth/`、`src/Example/Tag/` を参照し、設計パターンを把握。
2. `User`, `UserRepositoryInterface`, `PdoUserRepository`, `TokenIssuerInterface`, `LocalJwtIssuer` を作成。
   登録・ログインの UseCase/Handler/RouteRegistrar/ServiceProvider を実装。
3. `Task` ドメイン（7エンドポイント）を実装。`GET/PUT/DELETE /tasks/{id}` でオーナーシップチェック（403）。
4. `BearerTokenMiddleware` が exact-path のみのため、カスタムミドルウェア `TaskBearerAuthMiddleware` を新設（F-1）。
5. `RuntimeApplicationFactory` の型制約により、カスタムミドルウェアを渡せないため手動パイプライン構築（F-2）。
6. `composer check` 全通過・全7エンドポイント動作確認。

---

## Findings

### F-1: `BearerTokenMiddleware` の protected パスがダイナミックルートに非対応 [高]

`BearerTokenMiddleware` の `$protectedPaths` は exact-path マッチング（`in_array`）のみ。
`/tasks/{id}` のような動的パスを列挙できず、`$protectedPaths = []`（全パス保護）では `/auth/*` も 401 になる。

**解決**: プレフィックスマッチを行う独自ミドルウェア `TaskBearerAuthMiddleware` を実装。

**提案**: `BearerTokenMiddleware` に `$protectedPathPrefixes` オプションを追加する。

---

### F-2: `RuntimeApplicationFactory` にカスタム `MiddlewareInterface` を渡せない [中]

`RuntimeApplicationFactory` の `$authMiddleware` パラメータは `?BearerTokenMiddleware` 型に固定。
`MiddlewareInterface` 実装のカスタムミドルウェアを注入できない。`BearerTokenMiddleware` は `final` のため継承も不可。

**解決**: `RuntimeApplicationFactory` を使わず、個別ミドルウェアを組み合わせて手動パイプライン構築。

**提案**: `$authMiddleware` の型を `?MiddlewareInterface` に緩和する。

---

### F-3: JWT 発行 API が安定インターフェースとして未公開 [中]

`TokenVerifierInterface`（検証）は公開 API だが、`issue()` は `LocalBearerTokenVerifier`（`@internal`）に存在するのみ。
`TokenIssuerInterface` の公開 API がない。

**解決**: アプリ内で `TokenIssuerInterface` を独自定義し、`LocalBearerTokenVerifier::issue()` をラップ。

**提案**: `TokenIssuerInterface` を公開 API に追加し、`LocalBearerTokenVerifier` が実装するようにする。

---

### F-4: 開発ソース（`../NENE2/src/`）とインストール済みパッケージの API 乖離 [低]

`../NENE2/src/` に存在する `PaginationResponse` 等が `vendor/hideyukimori/nene2/` v1.4.0 にない。
PHPStan level 8 を実行するまで気づかなかった。

**提案**: CLAUDE.md に「`vendor/` を優先確認する」旨の注記を追加する。

---

## Test Results

```
PHPUnit:         12/12 tests passed
PHPStan level 8: No errors
PHP-CS-Fixer:    0 files to fix
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `BearerTokenMiddleware` がダイナミックルートに非対応 | 高 | ミドルウェア設計 |
| F-2 | `RuntimeApplicationFactory` にカスタムミドルウェアを渡せない | 中 | 拡張性 |
| F-3 | JWT 発行 API が安定インターフェース未定義 | 中 | API 設計 |
| F-4 | 開発ソースと公開パッケージの API 乖離 | 低 | ドキュメント |

---

## Recommendations

1. `BearerTokenMiddleware` にプレフィックスマッチ or 除外パスオプションを追加（F-1 対応、最優先）
2. `RuntimeApplicationFactory` の auth 引数型を `MiddlewareInterface` に緩める（F-2 対応）
3. `TokenIssuerInterface` を公開 API に追加し、how-to に JWT 発行フローを文書化（F-3 対応）
4. JWT 認証フロー実装ガイド（登録・ログイン・保護エンドポイントの最小構成例）を how-to に追加

---

## Overall Impression

NENE2 の基本設計（PSR-15 パイプライン、ServiceProvider、UseCase/Handler 分離、ValidationException → 422 自動マッピング）は一貫していて、Tag/Note のサンプルコードを参照すれば Task ドメインの実装は迷わず進められた。

JWT 認証フロー固有の課題は主に「`BearerTokenMiddleware` のパスマッチング方式」と「RuntimeApplicationFactory の柔軟性」の2点。どちらも深刻ではなく回避策があるが、how-to がなければ初見の実装者は同じところで躓く可能性が高い。
