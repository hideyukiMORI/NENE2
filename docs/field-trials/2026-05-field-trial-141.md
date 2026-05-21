# Field Trial 141 — リーダーボード（ランキングシステム）

**Date**: 2026-05-21  
**App**: `ranklog`  
**Path**: `/home/xi/docker/NENE2-FT/ranklog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.75  
**Special**: 脆弱性診断（3FT ごと）

---

## What was built

スコアを記録してランキングを表示するリーダーボードを実装した。

| Endpoint | 説明 |
|---|---|
| `POST /leaderboards` | リーダーボード作成 |
| `POST /leaderboards/{id}/scores` | スコア送信（ベストスコア保持） |
| `GET /leaderboards/{id}/rankings` | ランキング取得（上位N件・降順） |
| `GET /leaderboards/{id}/rankings/me` | 自分の順位とスコア |
| `DELETE /leaderboards/{id}/scores/{userId}` | スコア削除（本人のみ） |

---

## Architecture decisions

### ベストスコア保持（UPDATE パターン）

`UNIQUE (leaderboard_id, user_id)` でユーザーあたり1行を保証。`submitScore()` は既存スコアより高い場合のみ UPDATE し、戻り値で新ベスト達成かどうかを示す。

### 順位を COUNT(*) で計算

SQLite では `RANK()` ウィンドウ関数が使えないバージョンがある。代わりに `SELECT COUNT(*) WHERE score > ?` で自分より上位のユーザー数を数え、+1 した値が順位になる。同スコアは同順位になる。

### スコア所有権チェック（IDOR 防止）

DELETE エンドポイントで `$actorId !== $userId` を確認し、他ユーザーのスコアを削除できないようにする。

### limit パラメーターのクランプ

`?limit=99999` のような大きな値を渡されると全テーブルスキャンになる可能性がある。1〜100 の範囲にクランプする。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `RankTest.php` | 17 | Pass |
| `VulnTest.php` | 12 | Pass |
| **Total** | **29** | **Pass** |

---

## Vulnerability assessment (FT141)

| ID | 攻撃内容 | 期待値 | 結果 |
|---|---|---|---|
| VULN-A | IDOR: 他ユーザーのスコアを削除 | 403 | Pass |
| VULN-B | 他ユーザーのスコアを送信 | 200（許可） | Pass |
| VULN-C | SQL インジェクション in リーダーボード名 | 201（verbatim） | Pass |
| VULN-D | X-User-Id なしで /rankings/me | 400 | Pass |
| VULN-E | 非数値の X-User-Id | not 200 | Pass |
| VULN-F | 負の leaderboardId | not 200 | Pass |
| VULN-G | PHP_INT_MAX をスコアとして送信 | 200（有効な整数） | Pass |
| VULN-H | float のスコア（型混乱） | 422 | Pass |
| VULN-I | 文字列のスコア（型混乱） | 422 | Pass |
| VULN-J | X-User-Id なしで DELETE | 400 | Pass |
| VULN-K | user_id=0 でスコア送信 | 422 | Pass |
| VULN-L | `?limit=99999` の大きな limit | 200（クランプ済み） | Pass |

**全 12 件 Pass。脆弱性なし。**

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「リーダーボードはゲームで見慣れているので概念は理解しやすかった。ベストスコアだけを保持するロジックが `submitScore()` の戻り値で `true/false` として返ってくる設計は直感的。`COUNT(*)` で順位を計算するアイデアは目からウロコだった。SQL ウィンドウ関数を使わないでも実現できることが学べた。IDOR の説明（他ユーザーのスコアを削除しようとする攻撃）は実際の脆弱性として納得感があった。」

★★★★☆ — 概念が身近で学習しやすい

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel なら `User::withCount(['scores as higher_count' => fn($q) => $q->where('score', '>', $myScore)])` みたいなことをする。NENE2 では生 SQL を書くので少し冗長だが、`getUserRank()` というメソッド名でカプセル化されているので分かりやすい。ベストスコア保持の INSERT/UPDATE ロジックが Repository に閉じ込められているのは良い設計。`$isNewBest` の戻り値を API レスポンスに含めるアイデアは参考になる。」

★★★★☆ — Repository パターンが適切に機能している

### Persona 3 — セキュリティエンジニア

「12 件の脆弱性テスト全 Pass。VULN-H（float スコア）と VULN-I（string スコア）の型混乱チェックは PHP 8 の `json_decode` では float と string が `is_int()` で正しく弾かれるので適切。VULN-G の PHP_INT_MAX は 64-bit 整数として正常に保存されることを確認済み。VULN-A（IDOR）が 403 で止まるのは正確。limit クランプ（VULN-L）はパラメータインジェクション防止として重要。スコア送信で user_id 0 を弾く（VULN-K）も境界値チェックとして正しい。」

★★★★★ — 脆弱性テストの網羅性が高い。型チェックが丁寧

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /rankings` で `items`・`count`・`rank`・`score` が揃っているのでフロントで計算不要。`GET /rankings/me` で自分の順位だけを取得できるのは UX 上とても便利。`new_best: true/false` がスコア送信レスポンスに含まれるのは、ゲームの「新記録！」演出を実装するのに必要な情報。DELETE が 204 を返すのは HTTP として正しい。」

★★★★★ — API 設計がフロント要件をよく捉えている

### Persona 5 — インフラ・DevOps エンジニア

「`scores` テーブルは `(leaderboard_id, user_id)` のユニーク制約と `(leaderboard_id, score)` 複合インデックスが本番では必要になる。現状の `getUserRank()` は全スコアをスキャンするが、インデックスがあれば問題ない。limit クランプで最大 100 件に制限されているので極端なメモリ使用も防げる。SQLite で動作確認済みなので MySQL 移行も容易なはず（window 関数を使っていないため）。」

★★★★☆ — 本番化しやすい設計。インデックス追加で性能改善できる

### Persona 6 — プロダクトマネージャー

「ゲーム・コンテスト・学習プラットフォームなど幅広い用途に使えるリーダーボード。ベストスコア保持は多くのゲームの標準仕様に合っている。自分の順位をすぐ確認できる `GET /rankings/me` はモバイルアプリでよく使われるパターン。脆弱性診断で 12 件全 Pass しているのは安心感につながる。今後の拡張として、期間別ランキング（週次・月次）・チームランキング・スコア履歴などが考えられる。」

★★★★☆ — 汎用性の高いリーダーボード実装

---

## Howto

`docs/howto/leaderboard-ranking-system.md`
