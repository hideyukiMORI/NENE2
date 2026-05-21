# Field Trial Report — FT125: Tagging System (M:N)

**Date**: 2026-05-21
**Release**: v1.5.59
**App**: `taglog` (`/home/xi/docker/NENE2-FT/taglog/`)
**Tests**: 20/20 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement a many-to-many tagging system for posts. Core patterns: join table with composite PK, atomic tag replacement (delete-then-insert), N+1-free batch tag loading, tag-based post filtering via JOIN.

## Core Design

### Join Table

`post_tags(post_id, tag_id)` uses a composite primary key, which enforces uniqueness at the DB layer. `INSERT OR IGNORE` in the assignment path prevents duplicate key errors when the client sends the same tag name twice.

### Atomic Tag Replacement

`PUT /posts/{id}/tags` is a full replacement: delete all existing associations, then insert the new set. This is idempotent — calling it twice with the same payload is safe. Sending an empty `tags` array clears all tags.

Unknown tag names in the payload are silently skipped rather than returning an error. The API contract is: tags must be created first, then assigned. This keeps the assignment endpoint simple.

### N+1 Prevention

Loading a list of posts requires their tags. Rather than one `SELECT` per post, a single `IN` query fetches all tags for all result-set posts at once, grouped into a `Map<postId, Tag[]>` in PHP:

```sql
SELECT t.*, pt.post_id FROM tags t
INNER JOIN post_tags pt ON pt.tag_id = t.id
WHERE pt.post_id IN (?, ?, ...)
ORDER BY t.name ASC
```

Two DB round-trips total regardless of result set size.

### Tag Uniqueness

Tags are application-controlled entities (not free text). The `UNIQUE` constraint on `tags.name` enforces this at the DB level; duplicate creation returns 409 via the `\RuntimeException` catch pattern.

### Test Finding

`testCreateTagMissingNameReturns422` initially sent `[]` (empty PHP array → JSON `[]`). NENE2's `JsonRequestBodyParser` rejects a JSON array body with 400 because it expects an object. Fixed to send `['name' => '']` which decodes correctly and triggers the 422 validation path.

## Files

```
database/schema.sql
src/Tag/Tag.php
src/Tag/Post.php
src/Tag/TagRepository.php
src/Tag/RouteRegistrar.php
tests/Tag/TaggingTest.php    (20 tests)
docs/howto/tagging-system.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

「タグを付けるにはタグを先に作らないといけない」というルールが最初は分かりにくい。`PUT /posts/{id}/tags` に存在しないタグ名を送っても無視されるため、「なぜタグが付かないの？」と混乱しやすい。howto に「タグは先に POST /tags で作成してから割り当てる」と明記しているが、422 を返す設計にした方が初心者には親切だったかもしれない。N+1 の概念はまだピンと来ない段階なので、コメントが参考になる。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

`DELETE FROM post_tags + INSERT` のパターンは分かりやすい。ただし、トランザクションで囲まないと中断時に不整合が起きる点に気づかないかもしれない（今回は SQLite で実質シングルコネクションなので問題ないが、本番 MySQL で並列更新が来ると危ない）。`INSERT OR IGNORE` を使う理由（重複タグ名の排除）も、コメントがないと分からない。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

`PUT /posts/{id}/tags` のレスポンスがそのまま更新後の post オブジェクト（tags 込み）で返ってくるのは使いやすい。GET してから PUT してまた GET する必要がない。TypeScript 側でタグの型 `{ id: number; name: string; created_at: string }[]` を定義するのも自然。ただし、`tags` が未割り当ての場合に空配列 `[]` が返る（undefined ではない）のは明示的で良い。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

Laravel の `BelongsToMany::sync()` に相当するのが `setPostTags()` の delete+insert パターン。Laravel では `sync()` が差分更新（追加のみ/削除のみも選択可）だが、NENE2 の実装は full replace で明快。ただし、高トラフィック環境ではこの全削除→全挿入が楽観ロックと競合する場面がある。`findTagsByPostIds()` の IN プレースホルダ動的生成は Laravel の `whereIn()` に慣れた目には冗長に見えるが、生 SQL で明示されているのは正直で良い。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

コードレビューで指摘する点: `setPostTags()` がトランザクションなし。delete が成功して insert 途中でコネクションが切れると、タグが部分的にしか付かない状態になる。本番では `DatabaseTransactionManagerInterface` で囲むべき。`findTagsByPostIds()` の `$postIds` が空配列のとき early return しているのは正しい（空の IN 句は SQL エラーになる）。N+1 解決のバッチ fetch は良い設計。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — 6 ルートが RouteRegistrar に一覧されている。
- **薄いコントローラー**: ✓ — ビジネスロジックはリポジトリに。
- **No magic**: ✓ — M:N の実装が SQL で完全に可視。
- **RFC 9457 Problem Details**: ✓ — 全エラーが ProblemDetailsResponseFactory 経由。
- **設計懸念**: `setPostTags()` のトランザクション境界がない点は設計ポリシーの「トランザクション境界を明示する」に反する。次の FT でトランザクションをどこに置くかを再確認するきっかけにできる。
