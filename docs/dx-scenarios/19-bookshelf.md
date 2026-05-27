# DX Scenario 19: 読書記録 + 本棚

## アプリ概要

本・読書ステータス・評価・感想を管理する読書記録 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 本の登録 | `POST /books`（title, author, isbn, published_at） |
| 本棚追加 | `POST /users/{id}/bookshelf`（book_id, status: to_read/reading/finished） |
| ステータス更新 | `PATCH /users/{id}/bookshelf/{book_id}`（status, started_at, finished_at） |
| 本棚一覧 | `GET /users/{id}/bookshelf?status=finished&page=1` |
| レビュー | `POST /books/{id}/reviews`（rating 1-5, body） |
| 読書統計 | `GET /users/{id}/stats`（月別読了数・ジャンル別・平均評価） |
| ISBN 重複チェック | `GET /books?isbn=978...` |

ポイント: ユーザーごとの本棚（多対多 + 状態）、読書統計クエリ（月別集計）、ISBN 重複管理。

---

## Persona A — 永山 咲（新卒・女性・23 歳）

### 背景

文学部卒でプログラミングスクール経由。読書好きで「読書ノートアプリを作りたかった」。

### 作業シナリオ

1. `books` / `user_books` テーブルで設計。`user_books` は `user_id, book_id, status`。
2. `started_at` / `finished_at` を `user_books` に追加することを途中で気づく（最初は忘れていた）。
3. 読書統計: `GET /users/{id}/stats` で「月別読了数」を計算しようとして
   `strftime('%Y-%m', finished_at)` の SQL 関数を知らず詰まる。
4. 評価 `POST /books/{id}/reviews` に `UNIQUE(book_id, user_id)` を設定（1 冊 1 レビュー制）。
5. ISBN 重複チェックは `GET /books?isbn=...` で実装。

### ハマりポイント

- **`strftime()` SQL 関数**: SQLite での日付フォーマット関数を知らない。
- **`finished_at` の月次集計**: `GROUP BY strftime('%Y-%m', finished_at)` の書き方が分からない。
- **null チェック**: `finished_at IS NULL` の本は除外する必要があることを後で気づく。

### 解決策 & 感想

`strftime()` の使い方を SQLite ドキュメントで調べた。
月次集計クエリは Stack Overflow で見つけて動作を確認した。

> 「strftime って初めて知った。PHP の date() みたいなものなんだね。
>  SQLite の日付関数の howto が NENE2 にあれば速かった。」

### DX スコア: ⭐⭐⭐（3/5）

基本機能は完成。SQLite 日付関数の howto があれば改善が速い。

---

## Persona B — 村上 雅人（ロースキル・男性・29 歳）

### 背景

個人でいくつかの PHP アプリを作った経験あり 7 年目。読書記録アプリは個人プロジェクトで作ったことがある。

### 作業シナリオ

1. テーブル設計:
   - `books(id, title, author, isbn, genre, published_year)`
   - `bookshelf(user_id, book_id, status, rating, review, started_at, finished_at)` UNIQUE(user_id, book_id)
2. 月別読了数: `SELECT strftime('%Y-%m', finished_at) AS month, COUNT(*) AS count`
   `FROM bookshelf WHERE user_id=? AND status='finished' AND finished_at IS NOT NULL GROUP BY month`
3. ジャンル別統計: `books.genre` で JOIN して `GROUP BY genre`。
4. ISBN 重複: `books` テーブルに `UNIQUE(isbn)` 制約。重複 INSERT は 409 を返す。
5. `PATCH /users/{id}/bookshelf/{book_id}` で読了時に `finished_at = datetime('now')` を自動設定。

### ハマりポイント

- **NULL `isbn` の UNIQUE 制約**: ISBN がない本（古い本や自費出版）で `NULL` の場合、
  `UNIQUE(isbn)` 制約は NULL 同士を別物として扱う（SQLite の動作）ことを後で確認。
- **`strftime()` のパフォーマンス**: 月次集計で `strftime()` を使うとインデックスが効かない。
  大量データでは別カラム（`year_month`）を用意すべきか。
- **reading_speed 統計**: 「1 日何ページ読んだか」計算は `pages` カラムがないため今回省略。

### 解決策 & 感想

個人経験で設計はスムーズ。NULL UNIQUE の SQLite 挙動は確認が必要だった。

> 「NULL UNIQUE の挙動は SQLite と MySQL で違うから注意が必要。
>  NENE2 の howto に DB ごとの NULL 挙動の違いを書いてほしい。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。NULL UNIQUE の DB 差異と集計パフォーマンスの説明が欲しい。

---

## Persona C — 石川 典子（シニア・女性・39 歳）

### 背景

EdTech スタートアップのバックエンドリード。学習記録系アプリの開発経験豊富。

### 作業シナリオ

1. テーブル設計（正規化 + パフォーマンス考慮）:
   - `books(id, title, author, isbn, genre_id, published_year)` ← `genre` は外部テーブルで正規化
   - `genres(id, name)`
   - `bookshelf(user_id, book_id, status, started_at, finished_at)` UNIQUE(user_id, book_id)
   - `book_reviews(user_id, book_id, rating, body, created_at)` UNIQUE(user_id, book_id)
   - `finished_year_month` 計算カラムを `bookshelf` に追加して集計を高速化
2. 月別集計: `GROUP BY finished_year_month` でインデックスを活用。
3. 読書統計 UseCase として `ReadingStatsQuery` を作成（CQRS 的分離）。
4. ISBN 重複は `UNIQUE(isbn)` + NULL 扱いを明示した注記をドキュメントに記載。
5. `reading_duration_days` を `CAST(julianday(finished_at) - julianday(started_at) AS INTEGER)` で計算。

### ハマりポイント

- **計算カラム (Generated Column)**: SQLite 3.31+ で使える `AS (strftime(...)) STORED` 型の
  計算カラム。NENE2 のマイグレーション（Phinx）でサポートされているか確認が必要だった。
- **`julianday()` の精度**: 日数計算で浮動小数点誤差がでることがあり、`ROUND()` で対処。
- **`genre_id` の JOIN**: 全クエリで `JOIN genres` が必要になり冗長。非正規化も検討した。

### 解決策 & 感想

計算カラムは Phinx の Raw SQL マイグレーションで回避。高品質で完成。

> 「Phinx で Generated Column が書けるか調べるのに時間がかかった。
>  結局 Raw SQL で書いた。Phinx の使い方 howto に
>  Raw SQL マイグレーションの例があれば助かった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。Phinx の Raw SQL マイグレーションと SQLite 計算カラムの howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 永山（新卒） | ○ 基本機能完成 | 3/5 | SQLite `strftime()` 日付関数 |
| 村上（ロースキル） | ○ 良好に完成 | 3/5 | NULL UNIQUE の DB 差異、集計パフォーマンス |
| 石川（シニア） | ◎ 高品質完成 | 4/5 | Phinx Raw SQL マイグレーション、計算カラム |

**共通のフリクション**:
1. **SQLite 日付関数リファレンス** — `strftime()` / `julianday()` / `datetime()` の使い方 howto。
2. **Phinx の高度な使い方** — Raw SQL マイグレーション、インデックス作成、計算カラム。
3. **NULL UNIQUE の DB ごとの挙動差異** — SQLite vs MySQL での差を明示したドキュメント。
