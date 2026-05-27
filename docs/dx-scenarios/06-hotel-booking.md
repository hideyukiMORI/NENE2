# DX Scenario 06: ホテル予約

## アプリ概要

部屋管理・空室カレンダー・予約・キャンセルを備えたホテル予約 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 部屋管理 | `GET /rooms`, `POST /rooms`, `PUT /rooms/{id}` |
| 空室確認 | `GET /rooms/availability?check_in=2026-06-01&check_out=2026-06-03` |
| 予約作成 | `POST /reservations`（room_id, check_in, check_out, guest_name） |
| 予約確認 | `GET /reservations/{id}` |
| キャンセル | `DELETE /reservations/{id}`（→ 空室に戻す） |
| 予約一覧 | `GET /rooms/{id}/reservations?month=2026-06` |

ポイント: 期間ダブルブッキング防止、楽観ロックまたは DB ユニーク制約、キャンセル後の空室回復。

---

## Persona A — 高橋 悠（新卒・男性・25 歳）

### 背景

コンピュータサイエンス専攻卒 1 年目。アルゴリズムの授業で「区間重複」は学んだが実装未経験。

### 作業シナリオ

1. `rooms` と `reservations` テーブルを作成。`check_in` / `check_out` を `TEXT` で保存。
2. ダブルブッキング防止を「予約作成時に期間が重なる予約を SELECT して、あれば 409」と実装。
   トランザクションを使わないため、同時リクエストで二重予約が発生する。
3. 空室確認 `GET /rooms/availability` を「全部屋を返して、予約中のものに `available: false` をつける」
   実装にする（1 リクエストごとに複数クエリ発行）。
4. キャンセル `DELETE /reservations/{id}` は物理削除で実装。キャンセル記録が残らない。
5. 予約一覧は `BETWEEN` で期間フィルタするが、`check_in` と `check_out` の両方にまたがる予約が
   取れないバグが発生。

### ハマりポイント

- **期間重複チェックの SQL**: `(check_in < new_checkout) AND (check_out > new_checkin)` という
  重複条件を知らない。`BETWEEN` で取ろうとしてバグ。
- **同時実行とトランザクション**: 「SELECT してから INSERT」の間に他のリクエストが入れる問題。
- **キャンセルの履歴**: 物理削除でキャンセル記録が消えてしまう。

### 解決策 & 感想

先輩に「期間重複チェックの正しい SQL 条件」を教わった。
`docs/howto/` に「期間重複」の howto はなかったが、`soft-delete-restore-permanent.md` で
ソフトデリートのパターンを学びキャンセルを `cancelled_at` に変更した。

> 「期間重複の SQL 条件、考えてもなかなか出てこなかった。
>  あれは公式に howto があってほしい。あと同時実行は怖い。」

### DX スコア: ⭐⭐（2/5）

期間重複チェックの SQL と同時実行制御に詰まる。howto が必要なポイント。

---

## Persona B — 川口 みどり（ロースキル・女性・34 歳）

### 背景

旅行系 Web サイトの運用担当兼 PHP 担当 7 年。既存システムは CodeIgniter。

### 作業シナリオ

1. `rooms` / `reservations` テーブル設計。`status` = `confirmed/cancelled` で管理。
2. 期間重複チェックを以下の SQL で実装（CodeIgniter での経験を活かす）:
   ```sql
   WHERE room_id = ? AND check_in < ? AND check_out > ?
   ```
   正しい重複条件を使えた（経験値から）。
3. 予約作成はトランザクション内で「重複チェック + INSERT」を実行。
   `DatabaseTransactionManagerInterface` の使い方を `add-database-endpoint.md` で確認。
4. キャンセルはソフトデリート。`deleted_at` ではなく `status = 'cancelled'` で管理。
5. 空室確認は `NOT EXISTS` サブクエリで実装。少し遅いが動く。

### ハマりポイント

- **`NOT EXISTS` vs `LEFT JOIN` のパフォーマンス**: 遅いと気になるが最適化方法が分からない。
- **部分的なキャンセル**: チェックイン後のキャンセルと事前キャンセルで扱いが違うことを
  後で指摘される（今回は区別しない実装）。
- **連泊料金計算**: 「1 泊いくら×泊数」の料金計算をどこに置くか（UseCase? Repository?）迷う。

### 解決策 & 感想

トランザクション内の重複チェックは自力でできた。`NOT EXISTS` は動くので許容。

> 「重複チェックの SQL 条件は前の仕事で覚えてたから助かった。
>  でも howto に書いてなかったら新人は絶対詰まると思う。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。空室クエリの最適化と料金計算の置き場所ガイドが欲しい。

---

## Persona C — 木村 和雄（ベテラン・男性・52 歳）

### 背景

大手旅行会社の IT 部門出身、現在コンサルタント。
OTA（Online Travel Agency）のシステムを 20 年見てきた。
「ダブルブッキングは絶対に起こしてはいけない」という強い信念。

### 作業シナリオ

1. テーブル設計で `UNIQUE(room_id, check_in)` を仕込み、DB レベルで一意性を担保。
   ただし連泊の場合は各泊日をレコードで持つ `room_nights` テーブルを検討する
   （今回は簡易版として期間チェック方式に留める）。
2. 予約作成は `tx->run()` 内で:
   - 期間重複 SELECT（`FOR UPDATE` は SQLite では不可だが MySQL 対応を念頭に）
   - INSERT
3. 空室確認は一発 SQL で空き部屋を返す:
   ```sql
   SELECT * FROM rooms WHERE id NOT IN (
     SELECT room_id FROM reservations
     WHERE status = 'confirmed'
       AND check_in < :check_out AND check_out > :check_in
   )
   ```
4. キャンセルは `status = 'cancelled'` + `cancelled_at` 両方記録。
5. OpenAPI のレスポンス例を丁寧に書き `composer check` で全通過。

### ハマりポイント

- **`SELECT FOR UPDATE` の欠如**: SQLite では使えないため楽観ロックで代替。
  本番 MySQL でのロック戦略を howto に書いておきたいと感じた。
- **`room_nights` 正規化**: 日付ごとのレコードを持つ方が制約を強くできるが、
  NENE2 の推奨設計が不明。
- **期間またがりクエリ**: `check_in` が古くて `check_out` が新しい予約をまたぐケースの
  テストケースが howto にない。

### 解決策 & 感想

業務経験でほぼ自力完成。「どこかに悲観ロックの howto があれば嬉しい」。

> 「旅行システムの鉄則は全部知ってるから問題なかった。
>  NENE2 は SQLite がデフォルトだけど、本番は MySQL 使うときの
>  移行ガイドかロックの説明があれば初心者が助かると思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

業務知識があれば高品質に完成。ロック戦略のドキュメントが改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 高橋（新卒） | △ 重複バグあり | 2/5 | 期間重複 SQL 条件、同時実行制御 |
| 川口（ロースキル） | ○ 実用的な完成 | 3/5 | `NOT EXISTS` vs `LEFT JOIN`、料金計算の置き場所 |
| 木村（ベテラン） | ◎ 高品質完成 | 4/5 | SELECT FOR UPDATE/楽観ロック、MySQL 移行ガイド |

**共通のフリクション**:
1. **期間重複チェック SQL の howto がない** — `(start < end2) AND (end > start2)` という
   重複判定条件は多くのアプリで必要。専用 howto が価値高い。
2. **楽観ロック / 悲観ロックの選択** — 同時実行制御の戦略 howto が欲しい。
3. **ソフトデリート vs ステータス管理** — `deleted_at` vs `status=cancelled` の使い分け基準。
