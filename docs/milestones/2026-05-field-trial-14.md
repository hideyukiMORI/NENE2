# Milestone: Field Trial 14 — CompositeAuthMiddleware + M:N + 3 層アクセスモデル（postboard）

## 仮説

> `CompositeAuthMiddleware` と `BearerTokenMiddleware($protectedPathPrefixes)` を使い、
> 3 層アクセスモデル（public / Bearer / API Key）を、
> NENE2 のドキュメントだけを参照した Claude が摩擦なく構築できるか。

FT12-C (shoplog) では Consumer Project 側が独自 `MultiAuthMiddleware` を書く必要があった。
FT14 では公式 `CompositeAuthMiddleware`（PR #478）を使い、その使いやすさを検証する。

## テーマ: Multi-Auth Ergonomics

FT14 が叩く摩擦ポイント候補:

- `CompositeAuthMiddleware` の使いやすさとドキュメントの充実度
- `BearerTokenMiddleware(protectedPathPrefixes: ['/me/'])` の設定のしやすさ
- M:N 多対多リレーション（#457 howto 未整備のまま実装できるか）
- SQLite 外部キー（`PRAGMA foreign_keys = ON` の必要性）
- 3 層アクセスモデルのルーティング設計しやすさ

## 実装するアプリ: postboard

**投稿ボード API** — `composer require hideyukimori/nene2:^1.4` から 0 構築。
SQLite + CompositeAuthMiddleware + M:N（Post ↔ Tag）。

### ドメイン

- **Post**: タイトル・本文・公開フラグ・ユーザー紐付け（Bearer 保護 CRUD）
- **Tag**: 名前（API キー保護 CRUD）
- **PostTag**: Post ↔ Tag の M:N 中間テーブル

### エンドポイント

| Method | Path | 認証 | 備考 |
|---|---|---|---|
| GET | /posts | なし | 公開投稿のみ（is_public=true） |
| GET | /posts/{id} | なし | 公開投稿のみ |
| POST | /auth/register | なし | |
| POST | /auth/login | なし | |
| GET | /me/posts | Bearer | 自分の全投稿（非公開含む） |
| POST | /me/posts | Bearer | |
| PUT | /me/posts/{id} | Bearer | 自分の投稿のみ |
| DELETE | /me/posts/{id} | Bearer | 自分の投稿のみ |
| POST | /me/posts/{id}/tags/{tagId} | Bearer | タグ付け |
| DELETE | /me/posts/{id}/tags/{tagId} | Bearer | タグ外し |
| GET | /tags | なし | |
| POST | /tags | API キー | |
| DELETE | /tags/{id} | API キー | |

## Phases

### Phase 69 — Field Trial 14 Execution

- [ ] postboard リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [ ] CompositeAuthMiddleware を使った 3 層アクセスモデルを実装
- [ ] Post / Tag / PostTag ドメインを実装（M:N）
- [ ] Auth ドメイン（register / login / JWT）を実装
- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] 全エンドポイント動作確認
- [ ] 摩擦記録を `docs/field-trial-report.md` に残す
- [ ] フォローアップ Issue を開く

## 完了条件

- `composer check` 全通過
- 全エンドポイント動作確認
- 摩擦記録あり
- フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #479
- プロジェクト: `/home/xi/docker/postboard/`
- 前: FT13 (#472) — MySQL + Phinx (eventlog)
