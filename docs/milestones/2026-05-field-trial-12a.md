# Milestone: Field Trial 12-A — 多対多リレーションの AI 実装可能性検証

## 仮説

> 多対多リレーション（M:N）を持つドメインは、
> NENE2 のドキュメントだけを見た Claude が迷わず実装できるか。

Field Trial 11（tasklog）が見つけた摩擦は主に「認証エリア」系だった。
FT12-A では **リレーショナルデータ設計エリア** を集中的に叩く。

## テーマ: Relational Data Ergonomics

NENE2 の `DatabaseQueryExecutorInterface` は SQL の直接実行を前提とするが、
JOIN・中間テーブル・カスケード削除などの「複数テーブルにまたがる操作」に対する
ガイドは薄い。新規プロジェクトで M:N を設計する際に何が詰まるかを記録する。

## 実装するアプリ: tagmark

**ブックマーク管理 API** — `composer require hideyukimori/nene2:^1.4` から 0 構築。

### ドメイン

- **User**: 登録・ログイン（FT11 パターンを再利用）
- **Bookmark**: URL・タイトル・メモ（ユーザー所有）
- **Tag**: 名前（ユーザー所有）
- **Bookmark ↔ Tag**: 多対多（`bookmark_tags` 中間テーブル）

### エンドポイント

| Method | Path | 認証 | 備考 |
|---|---|---|---|
| POST | /auth/register | 不要 | |
| POST | /auth/login | 不要 | |
| GET | /bookmarks | Bearer | ページネーション + タグフィルタ |
| POST | /bookmarks | Bearer | |
| GET | /bookmarks/{id} | Bearer | タグ一覧を含む |
| PUT | /bookmarks/{id} | Bearer | |
| DELETE | /bookmarks/{id} | Bearer | 中間テーブルもカスケード削除 |
| GET | /tags | Bearer | |
| POST | /tags | Bearer | |
| DELETE | /tags/{id} | Bearer | 中間テーブルもカスケード削除 |
| POST | /bookmarks/{id}/tags/{tagId} | Bearer | タグ付与（冪等） |
| DELETE | /bookmarks/{id}/tags/{tagId} | Bearer | タグ除去 |
| GET | /bookmarks?tag={tagId} | Bearer | タグフィルタリング |

### 注目する摩擦ポイント候補

- M:N リポジトリ設計（中間テーブルの CRUD、JOIN クエリ）
- カスケード削除の実装パターン
- タグフィルタリング付きページネーション
- 所有権チェック（他ユーザーの Bookmark/Tag への操作は 403）
- FT11 パターン（JWT 認証）の再利用の容易さ

## Phases

### Phase 65 — Field Trial 12-A Execution ✅ 完了 (2026-05-19)

- [x] tagmark リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [x] User ドメイン（FT11 パターン再利用）を実装
- [x] Bookmark / Tag ドメイン（M:N）を実装
- [x] `composer check` 全通過（PHPUnit 19/19・PHPStan level 8・PHP-CS-Fixer）
- [x] 全エンドポイント動作確認（タグ付与・除去・フィルタリング・カスケード削除）
- [x] 摩擦記録を `docs/field-trial-report.md` に残す（7 件: F-1〜F-7）
- [x] フォローアップ Issue を開く（#457 — M:N howto）

## 結果

### テスト

```
PHPUnit 11: 19 tests, 45 assertions — OK
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

### 摩擦サマリー

| # | 種別 | 深刻度 | 対応 |
|---|---|---|---|
| F-1 | BearerTokenMiddleware: excludedPaths なし | 高 | v1.4.1 で解消（#440/#445） |
| F-2 | TokenIssuerInterface が v1.4 に不在 | 高 | v1.4.1 で解消（#442/#447） |
| F-3 | DomainExceptionHandlerInterface::handles() → supports() | 中 | v1.4.1 howto 修正済み |
| F-4 | ハンドラーが配列を返せると書いてある | 中 | v1.4.1 howto 修正済み |
| F-5 | getParsedBody() で JSON が読めない | 中 | v1.4.1 howto 修正済み |
| F-6 | M:N カスケード削除パターン未記載 | 低 | Issue #457 — 後続 howto |
| F-7 | RuntimeApplicationFactory に MiddlewareInterface を渡せない | 中 | v1.4.1 で解消（#441/#446） |

## 完了条件

- [x] `composer check` 全通過
- [x] M:N 操作（タグ付与・除去・フィルタリング）が動作
- [x] カスケード削除が動作
- [x] 摩擦記録あり
- [x] フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #453
- 報告書: `/home/xi/docker/tagmark/docs/field-trial-report.md`
- 次: FT12-B (#454) — MCP ファースト (knowledgelog)
