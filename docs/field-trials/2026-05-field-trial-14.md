# Field Trial 14 — postboard: CompositeAuthMiddleware + M:N 実地検証

## Date

2026-05-19

## Baseline

- NENE2 dev-main（c3513df、Packagist v1.4.1 には未収録）
- PHP 8.4
- プロジェクト: **postboard** — 投稿ボード JSON API
- エンティティ: `Post` / `Tag` / `PostTag`（M:N 中間テーブル）
- テスト: PHPUnit（31テスト）・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

1. `CompositeAuthMiddleware` を使って 3 層アクセスモデルを実現し、FT12-C (shoplog) で必要だった独自 `MultiAuthMiddleware` が不要になっているかを検証する。
2. M:N リレーション実装を howto なしで行い、摩擦を記録する。

---

## Findings

### F-1: `CompositeAuthMiddleware` が Packagist v1.4.1 に未収録 [高]

FT14 の主要検証ポイントである `CompositeAuthMiddleware` が、Packagist にリリース済みの v1.4.1 の `vendor/` に存在しなかった。

**解決**: `composer.json` に `path` リポジトリを追加し、`compose.yaml` で `../NENE2` をコンテナにマウントして `@dev` バージョンをインストール。

```json
"repositories": [{"type": "path", "url": "/nene2", "options": {"symlink": false}}],
"require": {"hideyukimori/nene2": "@dev"}
```

**提案**: `CompositeAuthMiddleware` を v1.5.0 としてリリースする（FT12-C の摩擦点を解消する機能のため優先度高い）。→ v1.5.0 でリリース済み。

---

### F-2: `ApiKeyAuthenticationMiddleware` がメソッド単位での保護に非対応 [中]

GET /tags は公開、POST /tags は API キー必須という設計を実装しようとした。`protectedPaths: ['/tags']` とすると GET /tags も POST /tags も同様に保護されてしまう。

**解決**: `CompositeAuthMiddleware` の API キーミドルウェア側に `/tags` を列挙せず、`TagRouteRegistrar` のハンドラー内で手動チェックする回避策を採用。

**提案**: `ApiKeyAuthenticationMiddleware` に `protectedMethods` 配列、または `[path => [method, ...]]` 形式の設定を追加する（Issue #461 と一致）。

---

### F-3: `ApiKeyAuthenticationMiddleware` がパスパラメーター（パターン）に非対応 [中]

DELETE /tags/{id} を API キー必須にしようとした。`protectedPaths` は完全一致で評価されるため、`/tags/{id}` のようなパターン文字列は動作しない。

**解決**: F-2 と同様にハンドラー内での手動チェックで回避。

**提案**: `ApiKeyAuthenticationMiddleware` に `protectedPathPrefixes` を追加するか、`BearerTokenMiddleware` と同様の prefix マッチオプションを提供する。

---

### F-4: `CompositeAuthMiddleware` の基本動作は直感的で成功 [情報]

`new CompositeAuthMiddleware([...middlewares])` とリスト渡しするだけで動作し、FT12-C で必要だった独自 `MultiAuthMiddleware` が不要になった。

`BearerTokenMiddleware` の `protectedPathPrefixes: ['/me/']` は `/me/posts`、`/me/posts/{id}`、`/me/posts/{id}/tags/{tagId}` すべてに正しく適用され、公開エンドポイント（`/posts`、`/auth/*`、`/tags`）は通過した。

---

## M:N リレーション実装パターン（howto なし）

NENE2 には M:N 専用の抽象化も howto も存在しないが、以下のパターンで実装できた:

1. `post_tags(post_id, tag_id, PRIMARY KEY(post_id, tag_id))` 中間テーブルを SQLite で定義。
2. `PostRepositoryInterface` に `attachTag(int $postId, int $tagId): void` と `detachTag()` を追加。
3. `INSERT OR IGNORE` で冪等な attach を実現。
4. `PRAGMA foreign_keys = ON` で SQLite 外部キーを有効化。

特段の摩擦はなかった。ただし、タグ一覧をポスト詳細と一緒に返す JOIN パターンを示す howto や example がない点は改善余地あり。

---

## CompositeAuthMiddleware 動作確認

| エンドポイント | 認証 | 実装方法 | 結果 |
|---|---|---|---|
| GET /posts | なし | — | ✅ |
| GET /posts/{id} | なし | — | ✅ |
| POST /auth/register | なし | — | ✅ |
| POST /auth/login | なし | — | ✅ |
| GET /me/posts | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| POST /me/posts | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| PUT /me/posts/{id} | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| DELETE /me/posts/{id} | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| POST /me/posts/{id}/tags/{tagId} | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| DELETE /me/posts/{id}/tags/{tagId} | Bearer | BearerTokenMiddleware（prefix） | ✅ |
| GET /tags | なし | — | ✅ |
| POST /tags | API キー | 手動チェック（F-2 回避策） | ✅ |
| DELETE /tags/{id} | API キー | 手動チェック（F-3 回避策） | ✅ |

---

## Test Results

```
PHPUnit:         31/31 tests
PHPStan level 8: No errors
PHP-CS-Fixer:    0 violations
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `CompositeAuthMiddleware` が Packagist v1.4.1 に未収録 | 高 | リリース漏れ |
| F-2 | `ApiKeyAuthenticationMiddleware` がメソッド単位での保護に非対応 | 中 | API 設計 |
| F-3 | `ApiKeyAuthenticationMiddleware` がパスパターンに非対応 | 中 | API 設計 |
| F-4 | `CompositeAuthMiddleware` の基本 API は直感的（情報） | — | 情報 |

---

## Overall Impression

`CompositeAuthMiddleware` の基本 API は直感的で、FT12-C で発生した「独自 MultiAuthMiddleware を書く必要がある」問題は解決されている。ただし `ApiKeyAuthenticationMiddleware` のパスパターン・メソッドフィルタリング非対応により、「GET は公開、POST は API キー必須」という一般的なユースケースに `CompositeAuthMiddleware` 単体では対応できず、ハンドラー内での手動チェック回避策が必要になる。F-1（リリース未収録）は v1.5.0 でリリースし解消済み。
