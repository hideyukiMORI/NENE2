# Field Trial 133 — Bookmark System

**Date**: 2026-05-21
**Version**: v1.5.67
**Project**: `bookmarklog`
**Theme**: User bookmark management with collection grouping
**Special**: MySQL Integration Tests (5 tests)

## Summary

Implemented a bookmark system covering add (idempotent), remove, list, count, and single-bookmark lookup. Collections allow grouping bookmarks by category. 22 tests total (17 SQLite + 5 MySQL), all passing.

## What Was Built

- `POST /users` — create a user
- `POST /items` — create a bookmarkable item
- `POST /users/{userId}/bookmarks` — add bookmark (idempotent; returns existing if already bookmarked)
- `DELETE /users/{userId}/bookmarks/{itemId}` — remove bookmark (204) or 404 if not found
- `GET /users/{userId}/bookmarks` — list all bookmarks (`?collection=` filter)
- `GET /users/{userId}/bookmarks/count` — total count
- `GET /users/{userId}/bookmarks/{itemId}` — check if specific item is bookmarked

Key design decisions:
- `UNIQUE (user_id, item_id)` at DB level — one bookmark per user per item
- Idempotent add: check-then-insert with `DatabaseConstraintException` catch for race conditions
- `collection = 'default'` fallback when not specified
- Remove returns 204 (deleted) or 404 (not found) — not silently 204 always
- `ORDER BY id DESC` for newest-first listing

## Test Results

| Suite | Tests | Result |
|---|---|---|
| BookmarkTest (SQLite) | 17/17 | PASS |
| MysqlBookmarkTest (MySQL) | 5/5 | PASS |
| **Total** | **22/22** | **PASS** |

```
OK (22 tests, 88 assertions)
```

MySQL run command:
```bash
docker run --rm --network nene2-ft_default \
  -v /path/to/bookmarklog:/app -w /app \
  -e MYSQL_HOST=mysql -e MYSQL_PORT=3306 -e MYSQL_DATABASE=ft_test \
  -e MYSQL_USER=ft_user -e MYSQL_PASSWORD=ft_pass \
  nene2-app composer test
```

## MySQL Integration Notes

- `SET FOREIGN_KEY_CHECKS = 0` before DROP TABLE — avoids FK dependency order issues with InnoDB
- `schema.mysql.sql` separate from `schema.sql` — MySQL requires `ENGINE=InnoDB`, `AUTO_INCREMENT`, `VARCHAR` instead of `TEXT`, named `UNIQUE KEY`
- Tests use the shared `ft_test` database (ft_user lacks CREATE DATABASE privilege)
- setUp/tearDown recreate tables for isolation — no test data bleeds across test cases

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 歴 6 ヶ月）

**印象**: ブックマーク追加が冪等なので、フロントエンドからリトライしても大丈夫という安心感がある。`DELETE` で 204 を返すパターンが「成功だけど返すものがない」と直感的に理解できた。コレクション機能がクエリパラメータ 1 つで実現できるシンプルさが好印象。

**摩擦点**: 「冪等性」という概念を最初に理解するのに時間がかかった。`DatabaseConstraintException` のキャッチが「レースコンディション対策」だと気づくには howto の説明が必要。

### Persona 2 — Laravel 経験者

**印象**: `UNIQUE (user_id, item_id)` 制約はEloquent の `unique` バリデーションルールと同じ思想。`firstOrCreate` 相当のロジックを手書きしている部分はボイラープレートだが、競合時の挙動が明示的で分かりやすい。`createEmpty(204)` が便利。

**摩擦点**: コレクション名を変更する（ブックマーク済みアイテムをコレクション間で移動する）エンドポイントが欲しい場合、現在の設計では DELETE + POST が必要。PATCH エンドポイントを追加する余地がある。

### Persona 3 — フロントエンドエンジニア（React 開発者）

**印象**: `GET /users/{userId}/bookmarks/count` があるのでバッジ表示が楽。リストレスポンスに `count` が含まれているのも便利（バッジと一覧を同時に更新できる）。`GET /users/{userId}/bookmarks/{itemId}` でアイテムがブックマーク済みかどうかを確認できるのでハートアイコンの初期状態表示に使える。

**摩擦点**: コレクション一覧（どんなコレクション名があるか）を取得するエンドポイントがないため、コレクション管理 UI を作るときに別途実装が必要。現 FT スコープでは適切。

### Persona 4 — セキュリティエンジニア

**印象**: 全クエリが `user_id` でフィルタリングされている。`find()` でクロスユーザーアクセスは不可能。`DatabaseConstraintException` のキャッチで constraint 違反が 500 にならない。`remove()` の rowCount チェックで「削除できたか」を明示的に確認している。

**改善点**: `addBookmark` ハンドラでアクターの確認がない（誰でも任意ユーザーにブックマークを追加できる）。本番では JWT クレームでユーザー ID を確認する必要がある（FT132 の `X-User-Id` パターンと同様）。

### Persona 5 — DevOps / SRE エンジニア

**印象**: MySQL 統合テストで FK 制約の DROP 順序問題を `SET FOREIGN_KEY_CHECKS = 0` で解決しているのは実践的。`schema.mysql.sql` と `schema.sql` を分離したことで SQLite（開発）・MySQL（本番）の両方に対応できる。`UNIQUE KEY uq_user_item` のインデックスが `(user_id, item_id)` クエリを高速化。

**摩擦点**: コレクション列に `VARCHAR(100)` で制限しているが、アプリ側で長さ検証をしていない。MySQL では切り捨てなしにエラーになるため、API 側で 100 文字制限を追加すべき（今回 FT スコープ外）。

### Persona 6 — テックリード（コードレビュー担当）

**印象**: 冪等性の実装がチェック→インサート→例外キャッチの 3 段階になっており、ロジックが完全にレポジトリに閉じている。`AppFactory` が SQLite と MySQL で同一のビジネスロジックを共有するように `buildApp()` を切り出しているのは良い設計。MySQL テストが `setUp`/`tearDown` で完全に分離されており、他のテストに影響しない。

**改善提案**: コレクション名の最大長バリデーションと、コレクション名に使用できる文字の制約（スラッシュ等）を追加すると堅牢になる。現在の FT スコープでは問題ない。

## Howto Coverage

- `docs/howto/bookmark-system.md` 追加
- 冪等 add パターン、`DatabaseConstraintException` catch、MySQL スキーマ差分、`SET FOREIGN_KEY_CHECKS` パターン、204 vs 404 を文書化
