# Field Trial 146 — コンテンツピン留め（Content Pinning）

**Date**: 2026-05-21  
**App**: `pinlog`  
**Path**: `/home/xi/docker/NENE2-FT/pinlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.80

---

## What was built

コンテンツ（記事）のピン留めシステムを実装した。
ユーザーが記事を順序付きでピン留めし、ダッシュボード等に固定表示できる。

| Endpoint | 説明 |
|---|---|
| `POST /pins` | 記事をピン留め（冪等: 既存 200 / 新規 201） |
| `DELETE /pins/{articleId}` | ピン解除（位置を自動整合） |
| `GET /pins` | ピン留め一覧（position 昇順） |
| `PUT /pins/order` | ピン留め順序変更 |

---

## Architecture decisions

### position カラムで順序管理

`position INTEGER` で 1 から始まる連続整数を保持する。
追加時は `MAX(position) + 1`、削除時は削除位置より後の行を -1 シフトして整合を維持。

### 冪等追加（201 / 200）

同じ記事を既にピン留めしている場合は INSERT しない（200 を返す）。
初回ピンは 201 Created を返す。ステータスで追加 / 既存を区別できる。

### 上限チェック（10 件）

カウントが上限以上かつ未登録の記事 → 422 with `max` フィールド。
冪等呼び出し（同じ記事のピン）は上限チェックをスキップ。

### 順序変更の完全一致チェック

`PUT /pins/order` は `article_ids` が現在のピン集合と完全一致（ソート比較）する場合のみ成功。
一致しない場合は 422 — 追加・削除なしで純粋な順序変更のみを受け付ける設計。

### Unpin 後の位置詰め

`DELETE FROM pins WHERE ... AND article_id = ?` の後に
`UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?` で整合。
SQLite のアトミック更新で安全。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `PinTest.php` (SQLite) | 19 | Pass |
| **Total** | **19** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「ピン留めは Twitter の固定ツイートや Slack の固定メッセージで知っている機能。
冪等設計（同じ記事を2回ピンしても壊れない）という考え方が実践的で学べた。
`position` の自動整合（unpin 後に詰める）が `UPDATE pins SET position = position - 1 WHERE ...`
という1行の SQL で実現できるのが驚きだった。順序変更（reorder）が完全一致チェックを
している理由（追加・削除と混同しないため）は設計の意図として理解できた。
ピン上限（10件）はハードコードより設定値にした方がいいのでは？と思ったが、
YAGNI（必要になってから変える）という判断として理解できた。」

★★★★☆ — 身近な UI 機能で概念が入りやすい

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `user->pins()->attach($articleId)` + `pivot` テーブルで似たことができるが、
`position` の自動整合を Eloquent だけで実現しようとするとかなりトリッキーになる。
NENE2 では Repository に明示的なロジックとして書かれているので可読性が高い。
冪等追加の戻り値（bool: true = 201, false = 200）パターンは
`firstOrCreate` の return value パターンと似ていて理解しやすい。
`reorder` の `sort() + 比較` による完全一致チェックは Laravel では書いたことがなかったが
堅牢な設計だと感じた。」

★★★★☆ — 明示的なロジックで理解しやすい

### Persona 3 — セキュリティエンジニア

「`X-User-Id` ヘッダーが全エンドポイントで必須チェックされている。
`GET /pins` も X-User-Id で絞り込むため他ユーザーのピン一覧を見ることはできない。
`DELETE /pins/{articleId}` は `WHERE user_id = ? AND article_id = ?` で所有権を検証している。
位置詰め操作（`UPDATE ... WHERE user_id = ?`）もユーザーフィルターが正確に適用されている。
気になる点: `PUT /pins/order` の `article_ids` に同じ ID が重複して含まれた場合の動作が
テストされていない（同じ記事を2度 UPDATE することになる）。本番では `array_unique` チェックを推奨。」

★★★★☆ — 基本的なアクセス制御は適切

### Persona 4 — フロントエンド開発者（API 利用者）

「ピン留め UI の実装に必要なものが揃っている。
`POST /pins` が 201（新規）/ 200（既存）で区別できるのでトースト表示の制御ができる。
`GET /pins` が `position` 順で返るので追加処理なしにリスト表示できる。
ドラッグ&ドロップで順序変更したあと `PUT /pins/order` を呼ぶだけでよい。
`count` フィールドがあるので『あと何件ピンできるか』（10 - count）を表示できる。
`DELETE /pins/{articleId}` が 204 No Content なので削除後のレスポンス解析が不要。」

★★★★★ — フロント実装がストレートにできる

### Persona 5 — インフラ・DevOps エンジニア

「`position` カラムにはインデックスがないが、1 ユーザーが最大 10 件のため
テーブルスキャンのコストは実質ゼロ。スケール時（ユーザー数増）に問題になるのは
`WHERE user_id = ?` のフィルタリングで、`(user_id, position)` 複合インデックスを
追加することで対応できる。`UPDATE SET position = position - 1` は SQLite で
アトミック実行されるので並行アクセスでも安全（SQLite はファイルロック）。
MySQL 移行時は `InnoDB` トランザクションで両 UPDATE をまとめて実行すべき。」

★★★★☆ — 小規模には十分、大規模インデックスは要検討

### Persona 6 — プロダクトマネージャー

「ピン留めはユーザーが使い続けるための重要な機能。お気に入り / ブックマークとの違いは
『少数の重要なものを画面上部に固定表示する』という UX の意図。
上限 10 件は適切（多すぎると意味がなくなる）。順序変更（drag & drop）は
コンテンツのパーソナライゼーション体験として重要。
今後の拡張として: ピン留めカテゴリ（仕事・趣味など）、ピン留め統計（何が多く固定されるか）、
チームピン（グループ全員に共有するピン）などが考えられる。」

★★★★☆ — プロダクト機能として整っている

---

## Howto

`docs/howto/content-pinning.md`
