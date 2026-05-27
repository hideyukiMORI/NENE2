# DX Scenario 30: ライブチャットログ

## アプリ概要

チャンネル・メッセージ・既読・検索を管理するチャットログ API。

| 機能 | エンドポイント例 |
|------|----------------|
| チャンネル管理 | `GET /channels`, `POST /channels`（name, type: public/private）|
| メンバー管理 | `POST /channels/{id}/members`, `DELETE /channels/{id}/members/{uid}` |
| メッセージ送信 | `POST /channels/{id}/messages`（body, reply_to_id）|
| メッセージ一覧 | `GET /channels/{id}/messages?before=<message_id>&limit=50`（カーソルページネーション）|
| 既読更新 | `POST /channels/{id}/read`（last_read_message_id）|
| 未読数 | `GET /channels/unread-counts`（チャンネル別未読件数）|
| メッセージ検索 | `GET /channels/{id}/messages?q=keyword` |
| メンション通知 | `GET /users/me/mentions` |

ポイント: カーソルベースページネーション、未読カウント（パフォーマンス重視）、メンション抽出。

---

## Persona A — 内山 雄太（新卒・男性・23 歳）

### 背景

コンピュータサイエンス学部卒業直後。Slack を毎日使うが「カーソルページネーション」は聞いたことない。

### 作業シナリオ

1. `channels` / `messages` テーブルを作成。
2. ページネーションを `LIMIT ? OFFSET ?` で実装（カーソルではなくオフセット）。
3. 未読カウントを「全メッセージを取得して PHP でカウント」する実装。
4. 既読管理を `channels.last_read_by_user_id = user_id` カラムで実装（複数ユーザー対応不可）。
5. メンション抽出を「`@` 記号を含むメッセージを全件取得してフィルタ」する。

### ハマりポイント

- **オフセットページネーションの問題**: 新しいメッセージが追加されると OFFSET がずれる
  （Slack のような無限スクロールでは致命的）。
- **既読管理の設計**: 複数ユーザーが同じチャンネルを見るには `channel_reads(channel_id, user_id, last_read_message_id)` テーブルが必要。
- **未読カウントの計算**: 全件取得は大量メッセージで遅い。

### 解決策 & 感想

`add-pagination.md` を読んだが「カーソルページネーション」の説明がなかった。
先輩に「`WHERE id < :cursor ORDER BY id DESC LIMIT N`」のパターンを教わった。

> 「カーソルページネーション、理解したら簡単だったけど
>  OFFSET 方式との違いを最初に説明してほしかった。
>  既読管理は1テーブルで複数ユーザー対応できないって分かった。」

### DX スコア: ⭐⭐（2/5）

カーソルページネーションと既読管理で詰まった。専用 howto が必要。

---

## Persona B — 川崎 真弓（ロースキル・女性・31 歳）

### 背景

IT 系スタートアップのカスタマーサポート兼エンジニア 5 年。Intercom 等のチャットツールを業務で使用。

### 作業シナリオ

1. テーブル設計:
   - `channels(id, name, type, created_by, created_at)`
   - `channel_members(channel_id, user_id, joined_at)` UNIQUE(channel_id, user_id)
   - `messages(id, channel_id, user_id, body, reply_to_id, created_at)`
   - `channel_reads(channel_id, user_id, last_read_message_id)` UNIQUE(channel_id, user_id)
2. カーソルページネーション（先輩に聞いた）:
   ```sql
   SELECT * FROM messages WHERE channel_id=?
   AND (:before IS NULL OR id < :before)
   ORDER BY id DESC LIMIT :limit
   ```
3. 未読カウント:
   ```sql
   SELECT COUNT(*) FROM messages m
   WHERE m.channel_id=? AND m.id > COALESCE(
     (SELECT last_read_message_id FROM channel_reads WHERE channel_id=? AND user_id=?), 0
   )
   ```
4. メンション: `body LIKE '%@username%'` で簡易抽出（正確でないが動く）。
5. プライベートチャンネルの認可: `channel_members` に存在するか確認。

### ハマりポイント

- **`COALESCE` でのデフォルト**: `channel_reads` にレコードがない（まだ一度も見ていない）場合の
  デフォルト値を `0`（全件未読）にした。
- **メンション抽出の正確さ**: `LIKE '%@username%'` は `@usernameA` にもマッチする。
  正規表現 or `@username ` のスペース込み検索に修正。
- **返信スレッドの表示**: `reply_to_id` で返信を管理しているが、スレッド表示の
  クエリが複雑になった（今回は省略）。

### 解決策 & 感想

カーソルページネーションは教わった後はスムーズに実装できた。

> 「カーソルページネーションのパターン、覚えたら使いやすい。
>  COALESCE のデフォルト値の考え方は面白かった。
>  返信スレッドは threaded-comments-api.md が参考になりそう。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。カーソルページネーションと未読カウントの howto があれば初から速い。

---

## Persona C — 福本 守（シニア・男性・42 歳）

### 背景

リアルタイムチャットシステム開発 12 年。WebSocket + HTTP の設計経験あり。

### 作業シナリオ

1. テーブル設計（パフォーマンス重視）:
   - `messages(id, channel_id, user_id, body, mentions_json, created_at)` + インデックス `(channel_id, id DESC)`
   - `mentions_json`: `["@user1","@user2"]` のリスト（正規化よりパフォーマンス優先）
   - `channel_reads(channel_id, user_id, last_read_message_id)` + `unread_count_cache` カラム
2. カーソルページネーションの実装。`has_more` フラグ: `LIMIT N+1` で取得して N+1 件あれば `true`。
3. 未読カウントキャッシュ: `channel_reads.unread_count_cache` を `messages` INSERT 時に +1、
   `POST /channels/{id}/read` 時に 0 にリセット（トランザクション内）。
4. メンション: `@username` をメッセージ保存時に正規表現で抽出して `mentions_json` に保存。
   `GET /users/me/mentions` は `mentions_json LIKE '%@me%'` で検索（妥協案）。
5. プライベートチャンネルのアクセス制御を Middleware + UseCase の 2 層でチェック。

### ハマりポイント

- **未読カウントキャッシュの同期**: `messages` INSERT と `channel_reads.unread_count_cache` の
  同時更新を全 `channel_members` 分行うと遅い。N+1 的な問題。
- **メンション検索の `LIKE` 限界**: `mentions_json LIKE '%@user1%'` は `@user10` にもマッチする。
  JSON 配列の要素検索は SQLite では `json_each()` を使うべきが複雑。
- **カーソルの不整合**: 新しいメッセージが `id < cursor` の範囲に Insert された場合（稀）の対応。

### 解決策 & 感想

高品質で完成。メンション検索は `json_each()` への改善を将来課題とした。

> 「SQLite の `json_each()` は知っていたけど NENE2 でどう使うかの例がない。
>  JSON 配列の要素検索パターンの howto があれば良かった。
>  未読カウントキャッシュは全 member 更新が重くなるので、
>  WebSocket やキューが使える環境なら非同期化が必要。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。`json_each()` と未読カウントキャッシュ戦略のドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 内山（新卒） | △ カーソル方式未使用 | 2/5 | カーソルページネーション、既読管理設計 |
| 川崎（ロースキル） | ○ 実用的完成 | 3/5 | メンション正規表現、スレッド表示 |
| 福本（シニア） | ◎ 高品質完成 | 4/5 | `json_each()` 配列検索、未読キャッシュ戦略 |

**共通のフリクション**:
1. **カーソルページネーション howto がない** — `add-pagination.md` がオフセット方式のみ。
   チャット・タイムライン系ではカーソル方式が必須。専用 howto の優先度が高い。
2. **既読管理パターン** — `channel_reads(channel_id, user_id, last_read_message_id)` テーブルの標準パターン。
3. **SQLite `json_each()` の使い方** — JSON 配列カラムの要素検索パターン howto。
