# Field Trial 149 — コンテンツコレクション（Content Collection）

**Date**: 2026-05-21  
**App**: `collectionlog`  
**Path**: `/home/xi/docker/NENE2-FT/collectionlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.83

---

## What was built

記事のキュレーションコレクション（公開/非公開）システムを実装した。
ユーザーが名前付きコレクションを作成し、記事を順序付きで追加できる。

| Endpoint | 説明 |
|---|---|
| `POST /collections` | コレクション作成 |
| `GET /collections/{id}` | コレクション取得（公開 or 自分） |
| `PUT /collections/{id}` | コレクション名・公開設定変更 |
| `DELETE /collections/{id}` | コレクション削除 |
| `POST /collections/{id}/items` | 記事を追加（冪等） |
| `DELETE /collections/{id}/items/{articleId}` | 記事を削除 |

---

## Architecture decisions

### 存在非公開パターン（404 vs 403）

非公開コレクションへのアクセスに対し 403 でなく 404 を返す。
403 は「存在するが権限がない」という情報を開示するため、
コレクションの存在自体を隠したいケースでは 404 が適切。
変更系操作（PUT/DELETE/POST items）では 403 を使用して権限不足を明示し、
GET のみ 404 で存在を隠蔽する。この使い分けがコレクションシステムの標準的な設計。

### 冪等アイテム追加（201/200）

`UNIQUE (collection_id, article_id)` を基盤として、
アプリ層での事前チェック（findItem）で 201/200 を切り替える。
上限チェックは「新規追加の場合のみ」実行し、既存の冪等呼び出しはスキップする。

### 位置整合（position compact）

アイテム削除後に `UPDATE ... SET position = position - 1 WHERE position > ?` でギャップを埋める。
ピン留め（FT146）と同じパターンを適用。最大 50 件制限下では性能問題なし。

### 複数パスパラメータ

`DELETE /collections/{id}/items/{articleId}` でルート内に 2 つのパスパラメータを持つ。
`Router::param($request, 'id')` と `Router::param($request, 'articleId')` をそれぞれ取得する。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `CollectionTest.php` (SQLite) | 20 | Pass |
| **Total** | **20** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「コレクション機能は Spotify のプレイリストや Pinterest のボードと同じ概念で入りやすかった。
非公開コレクションに 404 を返す設計が最初は混乱したが、
『403 は存在を認めること、404 は存在自体を隠す』という説明で理解できた。
冪等追加（同じ記事を 2 回追加しても壊れない）というパターンは、
ユーザーが『追加済みかどうか』を気にせずボタンを押せる UX に繋がると気づいた。
position の自動整合も FT146（ピン留め）と同じ SQL 1 行で実現できていて、
パターンとして覚えやすかった。」

★★★★☆ — 身近な UI 概念で実装パターンが学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `Collection::where('is_public', 1)->orWhere('user_id', $user->id)` という
Eloquent クエリ一発で書けるが、NENE2 では PHP コードで条件を書いている。
可読性的にはどちらも明確で甲乙つけがたい。
404 vs 403 の使い分けは Laravel の Policy でも同じ問題が起きる
（`before()` で 403 を返すか `view()` で false を返すか）。
NENE2 のアプローチは明示的で理解しやすい。
`PUT` エンドポイントで `array_key_exists('is_public', $body)` を使って
フィールドが送られてきた場合のみ更新する Partial Update パターンが
Laravel の `$request->has()` パターンと同じで親しみやすかった。」

★★★★☆ — 明示的な条件分岐で可読性高い

### Persona 3 — セキュリティエンジニア

「存在非公開パターン（404）は適切。非公開コレクションの ID 列挙攻撃に対して
存在自体を開示しない。
所有権チェック: POST /collections/{id}/items, PUT, DELETE はすべて
`user_id !== actorId` で 403 を返している ✓
冪等追加: `findItem` で既存チェック後に INSERT するため TOCTOU は
シングルスレッド・SQLite では問題なし。MySQL での並行アクセスは
UNIQUE 制約の `DatabaseConstraintException` をキャッチする追加実装が必要。
気になる点: コレクション削除時に items を物理削除している（`DELETE FROM collection_items`）。
これは正しいが、ON DELETE CASCADE を使う設計も検討価値がある。
最大 50 件制限の定数（MAX_ITEMS）が Repository に隠蔽されており、
ビジネスルールをアプリ層に明示する設計として適切。」

★★★★☆ — 基本的なアクセス制御・存在非公開は適切

### Persona 4 — フロントエンド開発者（API 利用者）

「ブックマーク/コレクション UI の実装がシンプル。
GET /collections/{id} が items を position 順で返すため追加ソート不要。
item_count フィールドで『あと何件追加できるか』（50 - count）を表示できる。
POST /collections/{id}/items が 201/200 で新規/既存を区別できるため、
追加ボタンの状態切り替え（『追加』→『追加済み』）が API だけで実現できる。
非公開コレクションの共有 URL を踏んだ場合は 404 が返るため、
フロント側では「見つかりません」UI を出すだけでよい（403 の場合より処理が単純）。
DELETE /collections/{id} 後に items も消える（サーバー側で整合済み）のは
フロント的に追加処理不要でよい。」

★★★★☆ — コレクション UI の実装がストレート

### Persona 5 — インフラ・DevOps エンジニア

「`collection_items` の `UNIQUE (collection_id, article_id)` インデックスが
冪等追加の基盤として機能する。`position` カラムは最大 50 件のため
フルスキャンでも問題なし。スケール時は `(collection_id, position)` 複合インデックスを追加。
`is_public = 1` でのグローバル公開コレクション一覧が必要になった場合は
`(is_public, created_at)` インデックスを追加すべき。
コレクション削除が 2 段階（items DELETE → collection DELETE）になっているが、
MySQL では ON DELETE CASCADE で 1 クエリにできる。
SQLite ではデフォルトで PRAGMA foreign_keys = ON が必要。」

★★★★☆ — 小規模に十分、スケールアップ時はインデックス追加

### Persona 6 — プロダクトマネージャー

「コレクション機能はユーザーエンゲージメントを高める重要な機能。
Spotify のプレイリスト、Pinterest のボード、YouTube の再生リストと同じ発想。
公開コレクションはバイラル性（シェア機能）に繋がる。
上限 50 件は使い勝手の観点から適切（多すぎるとコレクションの意味が薄まる）。
今後の拡張:
- コレクション一覧（GET /collections?user_id=X）
- コレクションへのいいね・フォロー
- 公開コレクションの検索
- コレクション内の順序変更（PUT /collections/{id}/items/order）— FT146 参照
冪等追加（201/200）はモバイルアプリの『追加済みか確認してからボタン表示切替』より
『押すたびに正しい状態を返す』設計で実装コスト削減。」

★★★★★ — プロダクト機能として即使えるレベル

---

## Howto

`docs/howto/content-collection.md`
