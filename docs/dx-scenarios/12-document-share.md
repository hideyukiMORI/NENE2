# DX Scenario 12: ドキュメント共有

## アプリ概要

ファイルアップロード・バージョン管理・アクセス権制御を備えたドキュメント共有 API。

| 機能 | エンドポイント例 |
|------|----------------|
| ドキュメント一覧 | `GET /documents`（自分がアクセス可能なもの） |
| アップロード | `POST /documents`（metadata）+ `PUT /documents/{id}/file` |
| バージョン | `POST /documents/{id}/versions`（新バージョンのファイル）|
| バージョン一覧 | `GET /documents/{id}/versions` |
| アクセス権管理 | `POST /documents/{id}/permissions`（user_id, role: viewer/editor） |
| 共有リンク | `POST /documents/{id}/share-links`（expires_at）|
| リンクアクセス | `GET /share/{token}` |
| ダウンロード | `GET /documents/{id}/download`（最新バージョン） |

ポイント: バージョン管理（N バージョン保持）、ACL（アクセス制御リスト）、共有リンクトークン。

---

## Persona A — 林 奈々（新卒・女性・22 歳）

### 背景

工業高校卒→専門学校でプログラミングを学び就職。ファイル操作の PHP 経験はほぼなし。

### 作業シナリオ

1. `documents` テーブルを作成。ファイルパスを `file_path TEXT` で保存する方針。
2. ファイルアップロードを PHP の `$_FILES` で実装しようとするが、NENE2 の PSR-7 環境では
   `$_FILES` が使えないことに気づく（`$request->getUploadedFiles()` が正解）。
3. バージョン管理を考えず、アップロードごとに `file_path` を上書きする設計にしてしまう。
4. アクセス権は `documents.owner_id` のみ（オーナーしかアクセスできない設計）。
5. 共有リンクトークンを `md5(time())` で生成してしまう（衝突・推測可能なトークン）。

### ハマりポイント

- **PSR-7 でのファイルアップロード**: `$_FILES` が使えないと気づくまでに時間がかかる。
- **バージョン管理の設計**: 「更新 = 上書き」ではなく「更新 = 新バージョン追加」の設計。
- **安全なトークン生成**: `md5(time())` の危険性を知らない。`random_bytes(32)` が正解。

### 解決策 & 感想

先輩に `$request->getUploadedFiles()` と `bin2hex(random_bytes(32))` を教わった。

> 「$_FILES が使えないってのが最初分からなかった。
>  PSR-7 でファイルアップロードする howto あれば速かった。
>  トークンは md5 で何が悪いの？って思ってたけど、
>  推測されると共有リンクが乗っ取られると聞いて怖くなった。」

### DX スコア: ⭐⭐（2/5）

PSR-7 ファイルアップロードとセキュアトークンの howto が不足。

---

## Persona B — 池田 俊輔（ロースキル・男性・38 歳）

### 背景

中小企業の IT 担当 10 年。ファイルサーバー管理経験あり。
PHP のファイル操作は基本的にできる。

### 作業シナリオ

1. `documents` / `document_versions` テーブルで設計。
   `document_versions.version_number` は `MAX(version_number)+1` で採番。
2. ファイルは `storage/uploads/{document_id}/{version_id}/filename` に保存。
   PSR-7 の `getUploadedFiles()` を調べて使用。
3. アクセス権は `document_permissions(document_id, user_id, role)` テーブルで実装。
   `GET /documents` は「自分が owner または permission を持つ」WHERE で取得。
4. 共有リンクは `share_links(token, document_id, expires_at)` テーブル。
   トークンは `bin2hex(random_bytes(16))` で生成。
5. ダウンロードは `readfile()` で実装。ただしメモリ消費に配慮していない。

### ハマりポイント

- **ファイルストレージの場所**: `storage/uploads/` を `public_html/` の外に置くべきかどうかが
  分からず、`public_html/uploads/` に置いてしまう（直 URL アクセス可能になる）。
- **`readfile()` のメモリ**: 大きなファイルでメモリが枯渇する可能性。
  `fpassthru()` + `fopen()` のストリーミングを後で調べた。
- **バージョン保持上限**: バージョンを何件まで保持するかの設定がなく無制限になる。

### 解決策 & 感想

大体完成したが、ストレージパスは後で指摘を受けて修正。

> 「uploads を public 外に置くのは知ってたつもりだったけど、
>  Web ルート外のパスをどう設定するかが NENE2 ではっきりしなかった。」

### DX スコア: ⭐⭐⭐（3/5）

動作するが静的ファイル直接アクセスの問題あり。ファイルストレージの howto が欲しい。

---

## Persona C — 谷口 恵子（シニア・女性・43 歳）

### 背景

エンタープライズ向けコンテンツ管理システム開発 15 年。セキュリティ審査経験あり。

### 作業シナリオ

1. テーブル設計:
   - `documents(id, title, owner_id, current_version_id, created_at)`
   - `document_versions(id, document_id, file_path, version_number, size_bytes, mime_type, uploaded_by, created_at)`
   - `document_permissions(document_id, user_id, role)` UNIQUE(document_id, user_id)
   - `share_links(id, token, document_id, version_id, expires_at, created_at)`
2. ファイルは `var/storage/documents/{document_id}/{version_id}/{filename}` に保存
   （`public_html/` 外）。ダウンロードは `StreamedResponse` 風に `fpassthru()` + Content-Disposition。
3. トークンは `bin2hex(random_bytes(32))` で生成（256 bit エントロピー）。
4. `GET /documents` は `JOIN document_permissions` または `WHERE owner_id=?` の OR 条件。
5. バージョン上限は `MAX_VERSIONS = 10` 定数で管理（超えたら古い版を削除）。

### ハマりポイント

- **ストリーミングダウンロード**: PSR-7 の `ResponseInterface` でファイルストリームを返す
   方法を確認するために `src/Http/` を読んだ（少し時間かかった）。
- **ACL の JOIN クエリ**: 「owner OR permission holder」の OR 条件クエリが冗長になった。
- **共有リンクのバージョン固定 vs 最新**: `share_links.version_id` で特定バージョンを
  固定する設計にしたが、「常に最新版を共有」のユースケースも対応が必要と後で気づいた。

### 解決策 & 感想

高品質で完成。ストリーミングダウンロードは少し手間がかかった。

> 「PSR-7 でファイルを返す方法の howto が欲しかった。
>  src を読めば分かるけど、分かるまでに時間がかかる。
>  セキュアトークン生成と Web ルート外ストレージは
>  セキュリティ howto に書いてあれば新人も間違えないと思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。ファイルレスポンスと ACL クエリのドキュメントが改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 林（新卒） | △ PSR-7 詰まり・セキュリティ問題 | 2/5 | PSR-7 ファイルアップロード、セキュアトークン |
| 池田（ロースキル） | ○ 動作するが改善点あり | 3/5 | ストレージパスのセキュリティ、メモリ効率 |
| 谷口（シニア） | ◎ 高品質完成 | 4/5 | ストリーミングダウンロード、ACL クエリ |

**共通のフリクション**:
1. **PSR-7 ファイルアップロード (`getUploadedFiles()`) の howto** — ほぼ全ペルソナが詰まる。
2. **Web ルート外ファイルストレージ** — `public_html/` 外への保存パターンと認証付きダウンロード。
3. **セキュアトークン生成** — `random_bytes(32)` の使い方と `md5(time())` の危険性説明。
