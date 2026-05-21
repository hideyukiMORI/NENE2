# Field Trial 131 — Voting System

**Date**: 2026-05-21
**Version**: v1.5.65
**Project**: `votelog`
**Theme**: Upvote/downvote with toggle, score, and per-user vote state

## Summary

Implemented a voting system covering cast/toggle/switch, score retrieval, and current vote state. 20 tests, all passing. No special assessments this round.

## What Was Built

- `POST /users` — create a user
- `POST /items` — create a votable item
- `POST /items/{itemId}/vote` — cast, switch, or toggle a vote
- `GET /items/{itemId}/score` — upvotes, downvotes, score
- `GET /items/{itemId}/vote/{userId}` — user's current vote state

Key design decisions:
- `UNIQUE (user_id, item_id)` enforces one-vote-per-user at DB level
- `CHECK (direction IN ('up', 'down'))` prevents invalid values at DB level
- `VoteDirection` backed enum — `tryFrom()` for clean 422 on invalid input
- Toggle logic in repository: same direction → DELETE, opposite → UPDATE, new → INSERT
- Score included in vote response — no separate round-trip needed for counter refresh
- `?VoteDirection` return from `castVote()` encodes removed state as `null`

## Test Results

| Suite | Tests | Result |
|---|---|---|
| VoteTest (SQLite) | 20/20 | PASS |

```
OK (20 tests, 78 assertions)
```

## Implementation Notes

The toggle pattern (same direction → remove) is idiomatic for voting UIs: users expect clicking the already-active vote button to deactivate it. A single `castVote()` entry point covers all three state transitions (no vote → vote, vote → no vote, up → down) which keeps the handler thin. The UNIQUE constraint prevents race conditions at the DB level, though concurrent toggle scenarios may need optimistic locking in high-traffic contexts.

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 歴 6 ヶ月）

**印象**: `VoteDirection` enum を使うことで「up か down しか入れられない」という制約が型で見えるのは分かりやすかった。`tryFrom()` が null を返すパターンを初めて見たが、`VoteDirection::tryFrom($dirStr) ?? null` で 422 を返す流れはシンプルで理解できた。スコアが投票レスポンスに含まれているのは「一石二鳥」で便利だと感じた。

**摩擦点**: 「トグル」という概念（同じ方向に 2 回投票 → 取消）がドキュメントに明記されていないと実装時に迷う。howto に記載して良かった。

### Persona 2 — Laravel 経験者

**印象**: Eloquent の `updateOrCreate` に相当するロジックを手書きしているが、明示的な SELECT → DELETE/UPDATE/INSERT の 3 分岐は Eloquent より読みやすい。`UNIQUE` 制約とアプリケーション側の一票制御が二重になっているのはフェイルセーフとして好ましい。

**摩擦点**: Laravel なら `Vote::updateOrCreate(['user_id' => $userId, 'item_id' => $itemId], ['direction' => $direction])` で済む。ボイラープレートが増えるが、その分ロジックが追いやすい。慣れれば問題ない。

### Persona 3 — フロントエンドエンジニア（React 開発者）

**印象**: 投票レスポンスにスコアが入っているので、ボタンクリックで即座に UI のカウンターを更新できる。`vote: null` で「投票なし」を表現しているので三項演算子 `vote === 'up' ? activeStyle : defaultStyle` で直接使える。`GET /items/{itemId}/vote/{userId}` で初期表示時のボタン状態を取得できるのも良い。

**摩擦点**: 楽観的更新（optimistic update）でトグルを先に描画してから API を叩く実装では、失敗時のロールバックが必要になる。API レスポンスに `previous_vote` フィールドがあるとロールバックが楽になる。

### Persona 4 — セキュリティエンジニア

**印象**: `CHECK (direction IN ('up', 'down'))` の DB レベル制約と `VoteDirection::tryFrom()` のアプリレベル制約が二重になっている。`UNIQUE (user_id, item_id)` で DB レベルの一票制御も確保されている。ユーザー/アイテム存在確認を `findUserById` / `findItemById` で行い 404 を返している。存在確認はタイミング攻撃の余地が小さい（boolean 返却、一定処理）。

**改善点**: 現在の設計では誰でも任意ユーザーの代わりに投票できる（認証なし）。実運用では JWT クレームからユーザー ID を取得し、`user_id` の詐称を防ぐ必要がある。FT としてのスコープは適切。

### Persona 5 — DevOps / SRE エンジニア

**印象**: `UNIQUE (user_id, item_id)` インデックスが自動で作成されるため、特定ユーザーの特定アイテムへの投票取得が高速。スコア計算が 2 回の COUNT クエリで済む（GROUP BY より単純で最適化しやすい）。アイテムが増えた場合は `(item_id, direction)` の複合インデックスが有効。

**摩擦点**: 現在の実装では高頻度な投票切り替えで UPDATE が走り続ける。レート制限（FT107）と組み合わせると安定する。

### Persona 6 — テックリード（コードレビュー担当）

**印象**: `castVote()` の 3 分岐（DELETE / UPDATE / INSERT）がレポジトリ内に完結しており、ハンドラが薄い。`?VoteDirection` 戻り値で「投票済み/取消」を型で表現しているのは設計として明快。enum の使用が PHP 8.1+ のベストプラクティスに沿っている。PHPStan level 8 / PHP-CS-Fixer 通過。

**改善提案**: `getScore()` が 2 本の SELECT になっているが、`SELECT direction, COUNT(*) as cnt FROM votes WHERE item_id = ? GROUP BY direction` の 1 本にまとめるとパフォーマンスが向上する。現在の件数規模では問題ないが、スケール時に検討すべき。

## Howto Coverage

- `docs/howto/voting-system.md` 追加
- トグル/スイッチロジック、enum による型安全、スコアの同梱、UNIQUE 制約の役割を文書化
