# Field Trial 130 — Notification Inbox

**Date**: 2026-05-21
**Version**: v1.5.64
**Project**: `notificationlog`
**Theme**: User notification inbox with read/unread state tracking

## Summary

Implemented a notification inbox API covering push delivery, unread filtering, single mark-as-read, bulk mark-as-read, and unread count. 23 tests, all passing. No special assessments this round.

## What Was Built

- `POST /users` — create a user
- `POST /users/{userId}/notifications` — push a notification to a user
- `GET /users/{userId}/notifications` — list notifications (newest-first; `?unread=true` for unread only)
- `GET /users/{userId}/notifications/unread-count` — badge counter
- `PATCH /users/{userId}/notifications/{id}/read` — idempotent single mark-as-read
- `POST /users/{userId}/notifications/read-all` — bulk mark-as-read

Key design decisions:
- `read_at` nullable timestamp instead of `is_read` boolean — preserves when it was read
- `ORDER BY id DESC` not `created_at DESC` — stable sort for same-timestamp events
- Cross-user 404 (not 403) — prevents notification ID enumeration
- Idempotent single read: checks `isRead()` before UPDATE, preserving original `read_at`
- Bulk read uses `WHERE read_at IS NULL` — idempotent, skips already-read rows

## Test Results

| Suite | Tests | Result |
|---|---|---|
| NotificationTest (SQLite) | 23/23 | PASS |

```
OK (23 tests, 65 assertions)
```

## Implementation Notes

The `read_at IS NULL` pattern is idiomatic SQL for tracking boolean state that also needs an audit timestamp. It eliminates a separate `updated_at` column for the read event and makes `WHERE read_at IS NULL` indexable. The unread count is included in list responses to avoid extra round-trips for badge UI updates.

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 歴 6 ヶ月、フレームワーク初経験）

**印象**: `read_at IS NULL = 未読` というパターンが直感的だった。boolean の `is_read` カラムより「いつ読んだか」まで記録できることに気づいてから腑に落ちた。`ORDER BY id DESC` の理由（同一タイムスタンプ問題）はコメントがあれば助かった。`JsonRequestBodyParser::parse()` を毎ハンドラに書く必要があるのは最初に驚いたが、howto を読んで「ミドルウェアではなく明示呼び出し」と分かれば問題ない。

**摩擦点**: PHP の `[]` が `json_encode` で `"[]"` になり JSON オブジェクトと混在する罠に引っかかった（テストで 400 が返ってきた）。

### Persona 2 — Laravel 経験者（NENE2 移行中）

**印象**: `nullable read_at` カラムは Eloquent でも同じ設計をするので自然。`markAllAsRead` が `WHERE read_at IS NULL` で冪等になっているのは良い。リポジトリパターンが薄く、クエリが透けて見えるのは Laravel の `Eloquent` に慣れていると最初は冗長に見えるが、テストで差し替えられる構造は好ましい。

**摩擦点**: `Router::PARAMETERS_ATTRIBUTE` を毎回書くのは Laravel の `$request->route('userId')` より煩雑。慣れれば許容範囲。

### Persona 3 — フロントエンドエンジニア（API 消費側、React 開発者）

**印象**: レスポンスに `unread_count` が含まれているのは嬉しい。バッジを更新するために別途 GET を叩かなくていい。`read` boolean フィールドがレスポンスにあるので、`read_at` を自分でチェックしなくていい。`PATCH /…/read` の冪等性が保証されているのでリトライ安全。

**摩擦点**: `PATCH` メソッドをサポートしないプロキシ環境だと `/read-all` の `POST` は通るのに単体読了が詰まる可能性がある。`POST /…/read` の別名があれば安心。

### Persona 4 — セキュリティエンジニア

**印象**: 他ユーザーの通知を既読にしようとすると 404 が返るのは適切（403 だと ID の存在が確認できてしまう）。`user_id` をすべてのクエリで絞り込んでいる。所有権チェックがリポジトリではなくハンドラ層で行われているのは意図的で正しい（ビジネスルール＝ハンドラ層の原則）。

**摩擦点**: 通知の `body` フィールドにサーバー側で生成された HTML が入りうるケースでは XSS リスクがあるが、今回の設計は JSON API なのでフロントがエスケープする責任。API ドキュメントに明記すべき点。

### Persona 5 — DevOps / SRE エンジニア

**印象**: SQLite で動くため開発環境のセットアップが軽い。`countUnread` が `COUNT(*)` ベースなので大量通知でも O(n) スキャンになりうる。本番では `(user_id, read_at)` の複合インデックスが必要。リストが `ORDER BY id DESC` で最新先頭なので、ページネーション追加時も OFFSET ではなくカーソルを `id < ?` で実装しやすい。

**摩擦点**: 現在の実装にページネーションがないため、ユーザーの通知が増えると全件返す。本番投入前に `LIMIT/OFFSET` またはカーソルの追加が必要。

### Persona 6 — テックリード（コードレビュー担当）

**印象**: `markAsRead` の `isRead()` 事前チェックが `read_at` の上書きを防いでいる点が丁寧。`markAllAsRead` が残余件数を返すのは API 設計として自然（クライアントが 0 を確認できる）。ハンドラが薄く、ロジックがリポジトリに集約されている。PHPStan level 8 / PHP-CS-Fixer 通過。

**改善提案**: `findByUserId` の `?bool $unreadOnly` パラメータは nullable boolean より enum（`FilterMode::ALL / UNREAD_ONLY`）の方が将来の拡張に強い（既読のみフィルターなど）。現時点では過剰設計なので今のままで良い。

## Howto Coverage

- `docs/howto/notification-inbox.md` 追加
- `read_at IS NULL` パターン、冪等マーク、クロスユーザー 404、`ORDER BY id DESC` の理由を文書化
