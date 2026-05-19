# Milestone: Field Trial 12-C — 公開 + ユーザー + 管理者の三層アクセス（Product Catalog API）

## 仮説

> API キー認証（管理者）・JWT Bearer 認証（ユーザー）・認証なし（公開）の 3 層が共存するアプリを、
> NENE2 のドキュメントだけを参照した Claude が迷わず実装できるか。

Field Trial 12-B（knowledgelog）が見つけた摩擦は主に「MCP ツール設計エリア」だった。
FT12-C では **複数の認証方式が共存する設計エリア** を集中的に叩く。

## テーマ: Multi-Auth Ergonomics

API キー認証と JWT Bearer 認証を同一アプリで組み合わせ、
公開エンドポイント・ユーザーエンドポイント・管理者エンドポイントを共存させる摩擦を記録する。

## 実装するアプリ: shoplog

**商品カタログ API** — `composer require hideyukimori/nene2:^1.4` から 0 構築。

### ドメイン

- **Product**: 名前・説明・価格・在庫（カテゴリ属する）
- **Category**: 商品カテゴリ
- **Favorite**: ユーザーのお気に入り商品（User ↔ Product の M:N）

### アクセスモデル

| 層 | 認証方式 | 操作 |
|---|---|---|
| 公開 | なし | 商品・カテゴリ一覧・詳細（読み取り専用） |
| ユーザー | JWT Bearer | お気に入り登録・削除・一覧 |
| 管理者 | API キー | 商品・カテゴリの CRUD（全権限） |

### エンドポイント

| Method | Path | 認証 | 備考 |
|---|---|---|---|
| GET | /products | 不要 | ページネーション |
| GET | /products/{id} | 不要 | |
| POST | /products | API キー | |
| PUT | /products/{id} | API キー | |
| DELETE | /products/{id} | API キー | |
| GET | /categories | 不要 | |
| POST | /categories | API キー | |
| POST | /auth/register | 不要 | |
| POST | /auth/login | 不要 | |
| GET | /me/favorites | Bearer | |
| POST | /me/favorites/{productId} | Bearer | 冪等 |
| DELETE | /me/favorites/{productId} | Bearer | |

### 注目する摩擦ポイント候補

- API キー認証と JWT Bearer 認証の共存設定
- 同じエンドポイントで「未認証は公開データ、認証済みは追加データ」の出し分け
- `RuntimeApplicationFactory` に複数の認証ミドルウェアを組み合わせる方法
- 認証方式ごとの `BearerTokenMiddleware` / `ApiKeyAuthenticationMiddleware` の順序
- お気に入り（User ↔ Product M:N）の所有権チェック

## Phases

### Phase 67 — Field Trial 12-C Execution ✅ 完了 (2026-05-19)

- [x] shoplog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4.1` で初期化
- [x] Product / Category ドメインを実装（公開 GET + API キー管理）
- [x] User / Auth ドメインを実装（JWT Bearer）
- [x] Favorite ドメインを実装（Bearer 必須、M:N）
- [x] `composer check` 全通過（PHPUnit 29/29・PHPStan level 8・PHP-CS-Fixer）
- [x] 全エンドポイント動作確認（3 層アクセスモデル: 公開 / Bearer / API キー）
- [x] 摩擦記録を `docs/field-trial-report.md` に残す（6 件: F-1〜F-6）
- [x] フォローアップ Issue を開く（#466–#469）

## 結果

### テスト

```
PHPUnit 11: 29 tests, OK
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

### Multi-Auth 設計パターン（FT12-C 採用）

```
Request
  └── MultiAuthMiddleware
        ├── /me/* → BearerTokenMiddleware (全パス保護)
        └── other → WriteApiKeyMiddleware (POST/PUT/DELETE → API キー)
```

### 摩擦サマリー

| # | 種別 | 深刻度 | 対応 |
|---|---|---|---|
| F-1 | RuntimeApplicationFactory が 1 つの $authMiddleware しか受け取れない | 高 | Issue #466 — 後続実装（CompositeAuthMiddleware） |
| F-2 | BearerTokenMiddleware の $protectedPaths が動的パスに対応しない | 高 | PR #470 で解消（$protectedPathPrefixes 追加） |
| F-3 | ApiKeyAuthenticationMiddleware が動的パス + メソッドに対応しない | 高 | Issue #461 — 後続実装 |
| F-4 | excludedPaths と Multi-Auth の組み合わせが分かりにくい | 中 | Issue #466 の howto で案内 |
| F-5 | LocalBearerTokenVerifier が @internal で使いにくい | 低 | PR #470 で解消（公開 API 昇格） |
| F-6 | PHPStan が Docker でメモリ不足になる | 低 | Issue #469 — howto 追記 |

## 完了条件

- [x] `composer check` 全通過
- [x] 3 層アクセスモデルが動作（公開 / Bearer / API キー）
- [x] 摩擦記録あり
- [x] フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #455
- 報告書: `/home/xi/docker/shoplog/docs/field-trial-report.md`
- F-2/F-5 対応 PR: #470 (マージ済み)
- 前: FT12-B (#454) — MCP ファースト (knowledgelog)
