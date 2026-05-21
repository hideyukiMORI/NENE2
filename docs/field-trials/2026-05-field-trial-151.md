# Field Trial 151 — ウィッシュリスト管理（Wishlist Management）

**Date**: 2026-05-21  
**App**: `wishlistlog`  
**Path**: `/home/xi/docker/NENE2-FT/wishlistlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.85

---

## What was built

優先度・メモ付きウィッシュリスト（欲しいものリスト）システムを実装した。
コンテンツコレクション（FT149）と似た構造だが、順序管理なし・priority/note メタデータあり・
上限なしが主な違い。

| Endpoint | 説明 |
|---|---|
| `POST /wishlists` | ウィッシュリスト作成 |
| `GET /wishlists/{id}` | ウィッシュリスト取得（公開 or 自分） |
| `PUT /wishlists/{id}` | 名前・公開設定変更 |
| `DELETE /wishlists/{id}` | ウィッシュリスト削除 |
| `POST /wishlists/{id}/items` | 商品を追加（冪等） |
| `DELETE /wishlists/{id}/items/{productId}` | 商品を削除 |

---

## Architecture decisions

### 順序なし設計（コレクションとの差別化）

コンテンツコレクション（FT149）は `position` を管理するが、
ウィッシュリストは追加順（INSERT 順）で返す。
ギフト登録・欲しいものリストのユースケースでは順序変更より優先度フラグの方が実用的。

### priority のフォールバックバリデーション

無効な priority 値はエラー 422 ではなく `'medium'` にフォールバックする。
クライアントが新しい priority 値を先行実装した場合の前方互換性を保つ。
DB の CHECK 制約と組み合わせて最終防衛。

### 冪等追加（201/200）

`UNIQUE (wishlist_id, product_id)` 制約を基盤とし、
アプリ層の事前チェック（`findItem`）で 201/200 を切り替える。
既存アイテムの場合は現在の priority と note を返す（ユーザーへの情報提供）。

### note フィールド（NULL 許容）

商品ごとのメモ（サイズ、色、理由等）を自由テキストで記録できる。
NULL（メモなし）とパターンを明示的に区別する。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `WishlistTest.php` (SQLite) | 23 | Pass |
| **Total** | **23** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「ウィッシュリストは Amazon の欲しいものリストや結婚式のギフト登録で馴染みある概念。
priority（high/medium/low）で商品の重要度を表現するパターンは、
タスク管理ツール（Trello のラベル等）と同じ発想で理解しやすかった。
コンテンツコレクション（FT149）の position 管理と比べて、
順序なし設計の方がシンプルで実装が軽い。
priority の無効値をエラーにせずにデフォルト値にフォールバックする設計は
『寛容な受信者』原則（Postel の法則）の実例として参考になった。
note フィールドを NULL 許容にする理由（メモなし vs 空文字の区別）も
実際のデータ設計として重要な学びだった。」

★★★★☆ — 身近な UI 概念でメタデータ設計が学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `$user->wishlists()->create([...])` でリレーションを通じて作成するが、
NENE2 では Repository で明示的に INSERT する。どちらも明確で理解しやすい。
priority のフォールバックバリデーションは Laravel の `$request->input('priority', 'medium')` と
同じ発想で親しみやすかった。
公開/非公開コレクションの 404 設計（IDOR 防止）は FT149 と同じパターンで
一貫性が保たれている点が安心できる。
note フィールドの NULL チェック `$body['note'] !== '' ? $body['note'] : null` は
Laravel の `$request->filled('note')` に相当するパターンとして覚えやすい。」

★★★★☆ — 一貫したパターンでフレームワーク全体の設計が学べる

### Persona 3 — セキュリティエンジニア

「存在非公開パターン（非公開ウィッシュリストへの非オーナーアクセスに 404）は FT149 と同じで適切。
所有権チェック: PUT/DELETE/POST items はすべて `user_id !== actorId` で 403 ✓
冪等追加の TOCTOU: `findItem` 後に並行 INSERT はシングルスレッド・SQLite では問題なし。
MySQL 並行環境では UNIQUE 制約の `DatabaseConstraintException` をキャッチする実装が必要。
priority の CHECK 制約（`priority IN ('high', 'medium', 'low')`）は
アプリ層フォールバックと DB 制約の二重防御として機能。
注目点: note フィールドが自由テキストのため、XSS に注意が必要。
API が JSON を返す場合は JSON エスケープが自動的に行われるため問題なし。
HTML レンダリング時は必ずエスケープすること。」

★★★★☆ — 基本的なアクセス制御・存在非公開は適切

### Persona 4 — フロントエンド開発者（API 利用者）

「ウィッシュリスト UI の実装がシンプル。
GET /wishlists/{id} が items を一括返却するため追加リクエスト不要。
item_count でリスト件数バッジを表示できる。
priority（high/medium/low）で色分けやアイコン表示を実装しやすい。
冪等追加（201/200）で「追加」→「追加済み」のボタン状態切り替えが API だけで実現できる。
note フィールドが null 許容なので、フロントでは `item.note ?? ''` で安全に処理できる。
公開ウィッシュリストの URL 共有（ギフト登録として）が、
非公開の場合は 404 を返すだけでよいシンプルな設計。」

★★★★☆ — ウィッシュリスト UI の実装がストレート

### Persona 5 — インフラ・DevOps エンジニア

「`wishlist_items.(wishlist_id, product_id)` の複合 UNIQUE インデックスが
冪等追加の基盤として機能し、重複チェックのクエリも高速。
priority の CHECK 制約は SQLite ではトリガー不要でネイティブ対応。
MySQL でも同様に機能する。
note フィールドの TEXT 型は SQLite では無制限だが、
MySQL で長さ制限（`VARCHAR(500)` 等）を設ける場合はマイグレーションで明示すること。
スケール時: `(user_id)` インデックスを wishlists テーブルに追加して
ユーザーのウィッシュリスト一覧取得を高速化（現在は `GET /wishlists/{id}` のみ）。」

★★★★☆ — 小規模に十分、スケール時はインデックス追加

### Persona 6 — プロダクトマネージャー

「ウィッシュリストは EC・ギフト・結婚式登録で必須機能。
priority（high/medium/low）は購入者・贈り主向けの明確なシグナル。
note フィールドで『○○色がいい』『S サイズ』等の詳細を記録できるのは
実際のギフト体験を大幅に改善する。
公開ウィッシュリストのシェア URL は SNS 拡散・プレゼント収集に活用できる。
今後の拡張:
- ウィッシュリスト一覧（GET /wishlists?user_id=X）
- アイテムの「購入済みマーク」（ギフト贈り主が実施）
- priority 別フィルタ（GET /wishlists/{id}?priority=high）
- ウィッシュリスト複製
- アイテムの優先度・メモ更新（PUT /wishlists/{id}/items/{productId}）
FT149（コレクション）と組み合わせると、
ユーザーのコンテンツ管理機能として包括的なプロダクトが完成する。」

★★★★★ — EC・ギフトプロダクトとして即使えるレベル

---

## Howto

`docs/howto/wishlist-management.md`
