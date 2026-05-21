# Field Trial 143 — 絵文字リアクション

**Date**: 2026-05-21  
**App**: `emojilog`  
**Path**: `/home/xi/docker/NENE2-FT/emojilog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.77  
**Special**: MySQL 統合テスト（5FT ごと）

---

## What was built

投稿に絵文字でリアクションを付けるシステムを実装した。

| Endpoint | 説明 |
|---|---|
| `POST /posts` | 投稿作成 |
| `POST /posts/{id}/reactions` | リアクション追加（同一絵文字は1ユーザー1回のみ） |
| `DELETE /posts/{id}/reactions/{emoji}` | リアクション削除（自分のみ） |
| `GET /posts/{id}/reactions` | リアクション集計（emoji 別カウント・ユーザー別リアクション） |

---

## Architecture decisions

### UNIQUE (post_id, user_id, emoji) で重複防止

同一ユーザーが同一投稿に同じ絵文字で複数回リアクションできないよう制約。異なる絵文字は OK（👍 と ❤️ は別行）。複数ユーザーが同じ絵文字を使うのも OK（各ユーザーで別行）。

### GROUP BY emoji で集計

リアクション数を COUNT(*) で集計し、カウント降順・絵文字名昇順でソート。集計は読み取り時に計算（カウントのためのカラムは持たない）。

### Optional actor で per-user リアクション

`GET /reactions` の `X-User-Id` ヘッダーはオプション。存在する場合、呼び出しユーザーが何の絵文字でリアクションしたかのリストを `user_reactions` として返す。フロントの UI でどの絵文字をハイライトするかの判定に使用。

### `mb_strlen` で絵文字長チェック

絵文字は Unicode マルチバイト文字のため `strlen` ではなく `mb_strlen` で長さを制限。8 文字以内に制限（ほとんどの絵文字シーケンスを許容）。

### PHPStan: `is_array()` on `fetchAll` result は常に true

`fetchAll` は `array<int, array<string, mixed>>` を返すため、各要素に対する `is_array()` チェックは常に true。代わりに `(array) $row` キャストを使用して `foreach` ループで処理。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `EmojiTest.php` (SQLite) | 18 | Pass |
| `MysqlEmojiTest.php` | 5 | Pass (MySQL環境) |
| **Total** | **23** | **Pass** |

---

## MySQL integration tests (FT143)

| テスト | 確認内容 |
|---|---|
| `testMysqlAddReactionAndGetCounts` | リアクション追加 → カウント確認 |
| `testMysqlDuplicateReactionReturns409` | 重複リアクション → 409 |
| `testMysqlRemoveReaction` | リアクション削除 → 204 |
| `testMysqlUserReactions` | ユーザー別リアクション確認 |
| `testMysqlMultipleEmojisOnSamePost` | 複数絵文字・複数ユーザーの集計 |

MySQL テストのキーポイント:
- `VARCHAR(32)` で emoji カラムを定義（UNIQUE キーに必要）
- `SET FOREIGN_KEY_CHECKS = 0` で FK 依存テーブルを安全に DROP
- `getenv()` は `=== false` でチェック（PHPStan level 8 対応）

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「絵文字リアクションは Twitter/Slack でよく使う機能なので概念がわかりやすかった。UNIQUE 制約が 3 カラム（post_id, user_id, emoji）にまたがるのは最初驚いたが、理由を聞いて納得した。`mb_strlen` と `strlen` の違いは知らなかった。絵文字が UTF-8 でマルチバイトという話は日本語の Web 開発をするときにもう一度思い出すことになりそう。`GROUP BY` での集計は SQL の基本なので理解しやすかった。」

★★★★☆ — 身近な機能で学習効果が高い

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel なら `Reaction::where('post_id', $postId)->groupBy('emoji')->count()` などで同様のことができる。NENE2 では GROUP BY を自分で書くが、Repository でカプセル化されているので handlers は読みやすい。`DatabaseConstraintException` のパターンは複数 FT で繰り返し登場するので覚えてきた。MySQL のスキーマで `VARCHAR(32)` を使う理由（UNIQUE キー）は実際のプロジェクトで役立つ知識。」

★★★★☆ — パターンの一貫性が学習を助ける

### Persona 3 — セキュリティエンジニア

「UNIQUE 制約 + `DatabaseConstraintException` で二重リアクションを DB レベルで防止しているのは正しい。削除は自分のリアクションのみ可能（`WHERE user_id = ?`）。`mb_strlen` で絵文字長制限があるので極端に長い文字列インジェクションを防止できる。MySQL の utf8mb4 設定が必要な点（VARCHAR で絵文字対応）は実際の設定ミスになりやすい箇所で、howto に明記されているのが良い。」

★★★★☆ — 基本的な安全性が確保されている

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /reactions` が `counts`・`total`・`user_reactions` を一度に返してくれるのがとても使いやすい。`user_reactions` でどの絵文字を自分が押したか確認できるのは「ハイライト」演出の実装に必須。`counts` が `{emoji: count}` のオブジェクト形式なのでフロントでの取り扱いが簡単。削除が `DELETE /reactions/{emoji}` で emoji を URL に含むのは REST として自然。」

★★★★★ — API レスポンス設計がフロント視点で完璧

### Persona 5 — インフラ・DevOps エンジニア

「MySQL と SQLite の両方でテスト通過済みなのは本番移行の安心感がある。MySQL で `VARCHAR(32)` を使うのは UNIQUE キーのインデックス制限回避として正しい。`utf8mb4` charset が必要で接続文字列に含まれているのが良い。`GROUP BY emoji` は `post_id` のインデックスがあれば効率的。テスト環境で MySQL コンテナが必要だが、環境変数なしで自動スキップされるので CI に優しい。」

★★★★★ — MySQL/SQLite 両対応が本番移行を容易にする

### Persona 6 — プロダクトマネージャー

「絵文字リアクションはソーシャル機能として欠かせない。1ユーザー1絵文字の制約は公平性を保証し、スパムを防ぐ。集計が `{emoji: count}` 形式なのでランキング表示も簡単。削除（取り消し）ができるのは UX として重要。今後の拡張として、リアクションへの通知・トップリアクターの表示・カスタム絵文字などが考えられる。」

★★★★☆ — ソーシャル機能として必要な要件が揃っている

---

## Howto

`docs/howto/emoji-reaction-system.md`
