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

### Phase 67 — Field Trial 12-C Execution

- [ ] shoplog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [ ] Product / Category ドメインを実装（公開 GET + API キー管理）
- [ ] User / Auth ドメインを実装（JWT Bearer）
- [ ] Favorite ドメインを実装（Bearer 必須、M:N）
- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] 全エンドポイント動作確認（3 層アクセスモデル）
- [ ] 摩擦記録を `docs/field-trial-report.md` に残す
- [ ] フォローアップ Issue を開く

## 完了条件

- `composer check` 全通過
- 3 層アクセスモデルが動作（公開 / Bearer / API キー）
- 摩擦記録あり
- フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #455
- 前: FT12-B (#454) — MCP ファースト (knowledgelog)
